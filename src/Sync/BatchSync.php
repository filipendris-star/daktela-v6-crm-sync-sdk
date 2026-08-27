<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsCursorPaginationInterface;
use Daktela\CrmSync\Adapter\SupportsDealLinkingInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\SkipIfExistsMode;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Exception\AdapterException;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Exception\MappingException;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Entity\EntityInterface;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\NestedValue;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\State\SyncStateStoreInterface;
use Daktela\CrmSync\Sync\Result\AccountSyncResult;
use Daktela\CrmSync\Sync\Result\RecordResult;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Psr\Log\LoggerInterface;

final class BatchSync
{
    /** @var array<string, array<string, string>> */
    private array $relationMaps = [];

    /** @var array<string, true> Keys are "entityType:crmId" */
    private array $syncingEntities = [];

    /**
     * Relations whose resolution failed in the CURRENT BATCH, "entity|crmId" =>
     * reason. Scoped to a batch, not a run: the point is to stop one broken
     * relation costing a CRM lookup per referencing record, and a batch bounds
     * that to one lookup per batch. Held for the whole run instead, a fault
     * lasting a single request condemned every record behind it in that step —
     * and because the surviving records keep the step out of the "all failed"
     * guard, the watermark advanced and the run reported clean.
     *
     * @var array<string, string>
     */
    private array $relationFailures = [];

    /** @var array<string, int> Tracks pagination offset per entity type (offset adapters) */
    private array $offsets = [];

    /** @var array<string, string|null> In-run resume cursor per key (cursor adapters) */
    private array $cursors = [];

    private bool $forceFullSync = false;

    public function __construct(
        private readonly ContactCentreAdapterInterface $ccAdapter,
        private readonly CrmAdapterInterface $crmAdapter,
        private readonly FieldMapper $fieldMapper,
        private readonly SyncConfiguration $config,
        private readonly LoggerInterface $logger,
        private readonly ?SyncStateStoreInterface $stateStore = null,
    ) {
    }

    public function setForceFullSync(bool $force): void
    {
        // Pagination state is bound to the query that produced it, and this flag
        // IS part of that query: a forced drain runs with since = null, so its
        // offsets and cursors index the full history. Carrying them into a
        // since-bound run makes the adapter skip past the incremental window —
        // which then reports zero records, clean, and advances the watermark over
        // everything it never read. Reached whenever a forced run is interrupted:
        // SyncEngine::fullSync() clears the flag in a finally, and the offsets
        // outlive it.
        if ($force !== $this->forceFullSync) {
            $this->resetOffsets();
        }

        $this->forceFullSync = $force;
    }

    public function resetOffsets(): void
    {
        $this->offsets = [];
        $this->cursors = [];
        $this->relationFailures = [];
    }

    /**
     * The drain's position within this run. Cursor state is deliberately NOT
     * persisted across runs: a token is only meaningful together with the moment
     * the drain started, and a resumed drain that then wrote a FRESH watermark
     * advanced the window past records it had never re-read — permanently, and
     * with nothing to show it. The offset path never had that hole because its
     * position is in memory too.
     *
     * So an interrupted drain restarts from the watermark on the next run. Pages
     * already processed are re-read (the adapter's upsert dedupes them) and
     * nothing is skipped.
     */
    private function resolveCursor(string $key): ?string
    {
        return $this->cursors[$key] ?? null;
    }

    /**
     * Record the page outcome: only a null next token means the
     * drain is complete — mark exhausted and clear the cursor so the next run
     * starts fresh. A short page is NOT treated as exhaustion: filtered searches
     * (e.g. HubSpot) legitimately return fewer rows than the limit while more
     * pages remain, and ending the drain there would strand the rest outside the
     * next incremental window. Otherwise keep the next token for the rest of THIS
     * run; see resolveCursor() for why it is never written to the state store.
     *
     * @param CursorPage<mixed> $page
     */
    private function advanceCursor(
        string $key,
        CursorPage $page,
        SyncResult $result,
        ?string $requestedCursor,
    ): void {
        // A page that hands back the very token it was asked for cannot advance:
        // the engine's drain loop would request it forever. That is the only
        // definitive stall signal — an EMPTY page with a *new* token is legitimate
        // (a scanned page whose rows were all filtered out server-side), and
        // treating it as the end would clear the cursor and let the watermark
        // advance over everything behind that token.
        // '' is not a position, so it means the same thing as null: the drain is
        // over. Treated as a live token it cost one wasted page request and then
        // an abort blaming the adapter for a stall it did not cause.
        $nextCursor = $page->nextCursor === '' ? null : $page->nextCursor;

        if ($nextCursor !== null && $nextCursor === $requestedCursor) {
            throw AdapterException::cursorPaginationStalled($key, $requestedCursor);
        }

        // A NEW token is trusted, however many record-less pages precede it: it is
        // the adapter stating there is more to read, and "runaway or just a large
        // sparsely-matching set?" cannot be decided from here — a filtered search
        // can legitimately scan thousands of pages that yield nothing. Capping it
        // does not help either: the cap has to persist the cursor and rethrow, so
        // the next run resumes and burns the same allowance again, forever. An
        // adapter whose has_more never turns false is a bug to fix in the adapter;
        // bounding a run's total work belongs to the process that schedules it.

        $exhausted = $nextCursor === null;
        $next = $nextCursor;

        $result->setExhausted($exhausted);
        $this->cursors[$key] = $next;
    }

    /**
     * Builds relation resolution maps by scanning mapping configs for relation definitions,
     * then iterating the relevant source entities to build CRM-ID → CC-ID maps.
     *
     * Optional: pre-populates the map for efficiency. Missing relations are auto-resolved on-the-fly.
     */
    public function buildRelationMaps(): void
    {
        $this->relationMaps = [];

        // Scan contact mappings for relation fields
        $contactMapping = $this->config->getMapping('contact');
        if ($contactMapping !== null) {
            foreach ($contactMapping->mappings as $mapping) {
                if ($mapping->relation === null) {
                    continue;
                }

                $this->buildRelationMap($mapping->relation);
            }
        }
    }

    /**
     * Returns the current relation maps (useful for webhook sync).
     *
     * @return array<string, array<string, string>>
     */
    public function getRelationMaps(): array
    {
        return $this->relationMaps;
    }

    public function syncContacts(): SyncResult
    {
        $this->relationFailures = [];
        $mapping = $this->config->getMapping('contact');
        if ($mapping === null) {
            throw new \RuntimeException('No mapping configured for contacts');
        }

        $since = $this->resolveSince('contact');
        $result = new SyncResult();
        $upsertFn = $this->buildUpsertFn('contact');

        if ($this->crmAdapter instanceof SupportsCursorPaginationInterface) {
            $limit = $this->config->batchSize;
            $cursor = $this->resolveCursor('contact');
            $page = $this->crmAdapter->fetchContactsPage($since, $cursor, $limit);
            foreach ($page->records as $contact) {
                $result->addRecord($this->syncEntityToCc($contact, $mapping, 'contact', $upsertFn));
            }
            $this->advanceCursor('contact', $page, $result, $cursor);
        } else {
            $offset = $this->offsets['contact'] ?? 0;
            $count = 0;
            $exhausted = true;

            foreach ($this->crmAdapter->iterateContacts($since, $offset) as $contact) {
                $result->addRecord($this->syncEntityToCc($contact, $mapping, 'contact', $upsertFn));
                $count++;

                if ($count >= $this->config->batchSize) {
                    $exhausted = false;
                    break;
                }
            }

            $result->setExhausted($exhausted);
            $this->offsets['contact'] = $exhausted ? 0 : $offset + $count;
        }

        $result->finish();

        $this->logger->info('Batch contact sync completed', [
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'skipped' => $result->getSkippedCount(),
            'failed' => $result->getFailedCount(),
            'incremental' => $since !== null,
        ]);

        return $result;
    }

    public function syncAccounts(): AccountSyncResult
    {
        $this->relationFailures = [];
        $mapping = $this->config->getMapping('account');
        if ($mapping === null) {
            throw new \RuntimeException('No mapping configured for accounts');
        }

        $since = $this->resolveSince('account');
        $result = new SyncResult();
        $autoContactResult = new SyncResult();
        $upsertFn = $this->buildUpsertFn('account');

        $processAccount = function (EntityInterface $account) use ($mapping, $upsertFn, $result, $autoContactResult): void {
            $record = $this->syncEntityToCc($account, $mapping, 'account', $upsertFn);
            $result->addRecord($record);

            if ($record->status !== SyncStatus::Failed) {
                $autoRecord = $this->autoCreateContactFromAccount($account, $record->targetId);
                if ($autoRecord !== null) {
                    $autoContactResult->addRecord($autoRecord);
                }
            }
        };

        if ($this->crmAdapter instanceof SupportsCursorPaginationInterface) {
            $limit = $this->config->batchSize;
            $cursor = $this->resolveCursor('account');
            $page = $this->crmAdapter->fetchAccountsPage($since, $cursor, $limit);
            foreach ($page->records as $account) {
                $processAccount($account);
            }
            $this->advanceCursor('account', $page, $result, $cursor);
        } else {
            $offset = $this->offsets['account'] ?? 0;
            $count = 0;
            $exhausted = true;

            foreach ($this->crmAdapter->iterateAccounts($since, $offset) as $account) {
                $processAccount($account);
                $count++;

                if ($count >= $this->config->batchSize) {
                    $exhausted = false;
                    break;
                }
            }

            $result->setExhausted($exhausted);
            $this->offsets['account'] = $exhausted ? 0 : $offset + $count;
        }

        $result->finish();
        $autoContactResult->setExhausted($result->isExhausted());
        $autoContactResult->finish();

        $this->logger->info('Batch account sync completed', [
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'skipped' => $result->getSkippedCount(),
            'failed' => $result->getFailedCount(),
            'incremental' => $since !== null,
        ]);

        if ($autoContactResult->getTotalCount() > 0) {
            $this->logger->info('Batch auto-contact sync completed', [
                'total' => $autoContactResult->getTotalCount(),
                'created' => $autoContactResult->getCreatedCount(),
                'updated' => $autoContactResult->getUpdatedCount(),
                'skipped' => $autoContactResult->getSkippedCount(),
                'failed' => $autoContactResult->getFailedCount(),
            ]);
        }

        return new AccountSyncResult($result, $autoContactResult);
    }

    /**
     * @param ActivityType[] $activityTypes
     */
    public function syncActivities(array $activityTypes = []): SyncResult
    {
        $mapping = $this->config->getMapping('activity');
        if ($mapping === null) {
            throw new \RuntimeException('No mapping configured for activities');
        }

        if ($activityTypes === []) {
            $entityConfig = $this->config->getEntityConfig('activity');
            $activityTypes = $entityConfig !== null ? $entityConfig->activityTypes : [ActivityType::Call];
        }

        $since = $this->resolveSince('activity');

        // First run (no cursor): with initial_sync "now" (the default) we seed the
        // cursor to the current time and push nothing — flooding the CRM with full
        // CC history is almost never intended. Opt out with `initial_sync: everything`,
        // or use forceFullSync for an explicit one-off historical push.
        if ($since === null && !$this->forceFullSync && $this->stateStore !== null) {
            // Only a *configured* activity entity opts into the seeding rail. A
            // direct programmatic call without config passed explicit types and
            // expects an export — silently seeding-and-skipping would surprise it.
            $activityConfig = $this->config->getEntityConfig('activity');
            if ($activityConfig !== null && $activityConfig->initialSync === 'now') {
                $seed = new \DateTimeImmutable();
                $this->stateStore->setLastSyncTime('activity', $seed);
                // Warning, not info: this run pushes nothing, and the state it
                // writes moves the window past everything older. On a genuine
                // first run that is the point; reached AFTER a resetState() it
                // silently discards the history the reset was meant to re-push,
                // so it has to be visible either way. Use forceFullSync for that.
                $this->logger->warning('Activity sync has no watermark — seeding to now; historical activities are NOT pushed (initial_sync: now). Use forceFullSync to push history.', [
                    'seeded' => $seed->format('c'),
                ]);

                $result = new SyncResult();
                $result->setExhausted(true);
                $result->finish();

                return $result;
            }
        }
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        // Lookup value => the CC activity that used it, for this batch. Two
        // activities sharing one value is proof the mapping cannot identify a
        // record: upsert would find and OVERWRITE the same CRM row for both.
        $lookupValuesSeen = [];

        foreach ($activityTypes as $type) {
            $typeMapping = $mapping->forType($type->value);

            // Offsets are tracked PER TYPE: a single shared offset would be fed
            // as the skip to every type's query, so whenever an earlier type
            // contributed rows to the batch, the next batch would start each
            // remaining type past rows it never read — silently losing them once
            // the incremental window advances.
            $offsetKey = 'activity:' . $type->value;
            $typeOffset = $this->offsets[$offsetKey] ?? 0;

            foreach ($this->ccAdapter->iterateActivities($type, $since, $typeOffset) as $activity) {
                $record = $this->syncActivityToCrm($activity, $typeMapping, $lookupValuesSeen);

                $result->addRecord($record);

                $this->offsets[$offsetKey] = ++$typeOffset;
                $count++;

                if ($count >= $this->config->batchSize) {
                    $exhausted = false;
                    break 2;
                }
            }
        }

        $result->setExhausted($exhausted);
        $result->finish();

        if ($exhausted) {
            foreach ($activityTypes as $type) {
                unset($this->offsets['activity:' . $type->value]);
            }
        }

        $this->logger->info('Batch activity sync completed', [
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'failed' => $result->getFailedCount(),
            'incremental' => $since !== null,
        ]);

        return $result;
    }

    /**
     * Sync one batch of records for a configured custom entity, in the entry's direction:
     * crm_to_cc imports CRM records into a Daktela entity (the original behavior);
     * Import only in this release; the loader rejects any other direction.
     */
    public function syncCustomEntity(
        \Daktela\CrmSync\Config\CustomEntitySyncConfig $entry,
        MappingCollection $mapping,
    ): SyncResult {
        // Same batch scoping as syncContacts()/syncAccounts(): held across a whole
        // drain, one transient relation fault condemns every record behind it,
        // and the surviving records keep the step out of the "all failed" guard —
        // so the watermark advances and the run reports clean.
        $this->relationFailures = [];

        // No rules means an empty payload, which would write a blank record.
        if ($mapping->mappings === []) {
            throw MappingException::emptyRuleSet(sprintf('custom entity "%s"', $entry->name));
        }

        $offsetKey = "custom:{$entry->name}";
        $since = $this->resolveSince($offsetKey);
        $offset = $this->offsets[$offsetKey] ?? 0;
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        $upsertFn = $this->buildUpsertFn($entry->target);

        foreach ($this->crmAdapter->iterateCustomEntity($entry->source, $since, $offset) as $rawRecord) {
            $entity = $this->wrapForTarget($entry->target, $rawRecord);

            $record = $this->syncEntityToCc(
                entity: $entity,
                mapping: $mapping,
                entityType: $entry->target,
                upsertFn: $upsertFn,
            );

            $result->addRecord($record);
            $count++;

            if ($count >= $this->config->batchSize) {
                $exhausted = false;
                break;
            }
        }

        $result->setExhausted($exhausted);
        $result->finish();

        if ($exhausted) {
            $this->offsets[$offsetKey] = 0;
        } else {
            $this->offsets[$offsetKey] = $offset + $count;
        }

        $this->logger->info('Batch custom entity {name} sync completed (source: {source}, target: {target})', [
            'name' => $entry->name,
            'source' => $entry->source,
            'target' => $entry->target,
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'skipped' => $result->getSkippedCount(),
            'failed' => $result->getFailedCount(),
            'incremental' => $since !== null,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $rawRecord
     */
    private function wrapForTarget(string $target, array $rawRecord): EntityInterface
    {
        $id = isset($rawRecord['id']) ? (string) $rawRecord['id'] : null;
        unset($rawRecord['id']);

        return match ($target) {
            \Daktela\CrmSync\Config\CustomEntitySyncConfig::TARGET_CONTACT
                => new Contact($id, $rawRecord),
            \Daktela\CrmSync\Config\CustomEntitySyncConfig::TARGET_ACCOUNT
                => new \Daktela\CrmSync\Entity\Account($id, $rawRecord),
            default => throw new \LogicException(sprintf(
                'custom_entities target "%s" is not supported by ContactCentreAdapterInterface yet. '
                . 'Supported targets: contact, account. Extend BatchSync::wrapForTarget() and '
                . 'buildUpsertFn() to add more.',
                $target,
            )),
        };
    }

    /**
     * @param callable(string, array<string, mixed>): UpsertResult $upsertFn
     */
    private function syncEntityToCc(
        EntityInterface $entity,
        MappingCollection $mapping,
        string $entityType,
        callable $upsertFn,
    ): RecordResult {
        try {
            $this->ensureMappingRelations($entity, $mapping);

            $mapped = $this->fieldMapper->map($entity, $mapping, SyncDirection::CrmToCc, $this->relationMaps);
            $upsertResult = $upsertFn($mapping->lookupField, $mapped);
            $synced = $upsertResult->entity;

            $status = match (true) {
                $upsertResult->skipped => SyncStatus::Skipped,
                $upsertResult->created => SyncStatus::Created,
                default => SyncStatus::Updated,
            };

            $record = new RecordResult(
                entityType: $entityType,
                sourceId: $entity->getId(),
                targetId: $synced->getId(),
                status: $status,
            );

            if ($record->status !== SyncStatus::Failed && $entity->getId() !== null && $record->targetId !== null) {
                $this->relationMaps[$entityType] ??= [];
                $this->relationMaps[$entityType][(string) $entity->getId()] = $record->targetId;
            }

            return $record;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync {type} {id}: {error}', [
                'type' => $entityType,
                'id' => $entity->getId(),
                'error' => $e->getMessage(),
            ]);

            return new RecordResult(
                entityType: $entityType,
                sourceId: $entity->getId(),
                targetId: null,
                status: SyncStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @param array<string, string> $lookupValuesSeen lookup value => CC id that used it,
     *                                                for this batch (by reference)
     */
    private function syncActivityToCrm(Activity $activity, MappingCollection $mapping, array &$lookupValuesSeen = []): RecordResult
    {
        try {
            $mapped = $this->fieldMapper->map($activity, $mapping, SyncDirection::CcToCrm, $this->relationMaps);
            if ($mapped === []) {
                // A ConfigurationException, so it aborts the step like the lookup
                // checks below rather than failing this one record. A mapping
                // with no rules for a type is wrong for every activity of that
                // type, and failing them one at a time makes a mixed batch a
                // PARTIAL failure — which advances the watermark past exactly the
                // records it refused. That was live here until now.
                //
                // The cost is a stall: one undedupable or unmapped type stops the
                // whole activity step, so a healthy type stops progressing too
                // until the mapping is fixed. Deliberate — a visible stall that
                // repeats every run is recoverable; silent loss is not.
                throw ConfigurationException::activityMappingProducesNoPayload(
                    $activity->getActivityType()?->value,
                );
            }

            // The one place this is knowable: does THIS record carry a value at
            // the mapping's lookup_field? Without it upsertActivity() has nothing
            // to look up and creates on every run — silently, since "found
            // nothing then created" and "created" look identical downstream.
            //
            // A config-load check was tried and removed: it could only see that
            // some rule targets the field, not that the rule fired for this
            // record, and it grew a hole for every way of getting that wrong
            // (a lookup_field naming a cc_field, one written only under a
            // `types:` block that did not apply here, a dotted path
            // Activity::get() cannot resolve, a static or append rule, a mapping
            // built in code and never loaded from YAML).
            $mappedActivity = Activity::fromArray($mapped);

            if ($activity->getActivityType() !== null) {
                $mappedActivity->setActivityType($activity->getActivityType());
            }

            $linkDeal = $this->config->getEntityConfig('activity')?->linkDeal;
            if ($linkDeal !== null && $this->crmAdapter instanceof SupportsDealLinkingInterface) {
                $mappedActivity = $this->crmAdapter->linkActivityToDeal($mappedActivity, $linkDeal);
            }

            // Checked HERE, on the object the adapter is about to receive, and
            // not on the $mapped array above. linkActivityToDeal() is documented
            // as returning "the same or an augmented activity", so it may hand
            // back a fresh instance — and then a check against $mapped would pass
            // while the adapter read null from the entity it actually got.
            // Activity::fromArray() also drops keys ($activity_type), so the two
            // are not interchangeable.
            $lookupValue = $mappedActivity->get($mapping->lookupField);
            if ($lookupValue === null || $lookupValue === '' || is_array($lookupValue)) {
                throw ConfigurationException::activityExportCannotDedupe(
                    $mapping->lookupField,
                    array_keys($mappedActivity->toArray()),
                );
            }

            // Non-empty is not enough: it has to IDENTIFY the record. A rule that
            // yields a constant (a static value, or a default_value transformer
            // firing on an absent source) passes the check above and then makes
            // upsert find the same CRM row for every activity — each one
            // overwriting the last, reported as a clean run. That is worse than
            // the duplicates this guard was built for: it is silent deletion.
            //
            // Best-effort, NOT proof: this only sees activities read in the same
            // drain. A constant escapes it when the drain holds a single record
            // (batch_size 1, or a quiet tenant), and two activities that share a
            // non-unique value can land in different drains. It catches the
            // common shapes; it cannot promise the mapping is identifying.
            $lookupKey = (string) $lookupValue;
            $ccId = (string) $activity->getId();
            if ($ccId !== '' && isset($lookupValuesSeen[$lookupKey]) && $lookupValuesSeen[$lookupKey] !== $ccId) {
                throw ConfigurationException::activityExportLookupIsNotUnique(
                    $mapping->lookupField,
                    $lookupKey,
                    $lookupValuesSeen[$lookupKey],
                    $ccId,
                );
            }
            if ($ccId !== '') {
                $lookupValuesSeen[$lookupKey] = $ccId;
            }

            // Lookup-then-write. The adapter is the only thing that can see the
            // CRM, so it decides whether this is a create or an update.
            $result = $this->crmAdapter->upsertActivity($mapping->lookupField, $mappedActivity);

            // Updated, meaning "the CRM now matches". upsert does not report
            // which branch it took — it returns an Activity with no created flag
            // — so this is the one verdict that is never wrong. An earlier
            // version compared the CC activity's id with the CRM record's id,
            // two different systems' identifiers that are never equal, so every
            // upsert reported Created including in-place updates.
            return new RecordResult(
                entityType: 'activity',
                sourceId: $activity->getId(),
                targetId: $result->getId(),
                status: SyncStatus::Updated,
            );
        } catch (ConfigurationException $e) {
            // Deliberately NOT turned into a Failed record. The mapping is wrong
            // for every activity, so failing one at a time would make a mixed
            // batch a PARTIAL failure — and saveState() advances the watermark on
            // partial failure, putting the refused records outside every future
            // window. Aborting the step holds the watermark instead.
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync activity {id}: {error}', [
                'id' => $activity->getId(),
                'error' => $e->getMessage(),
            ]);

            return new RecordResult(
                entityType: 'activity',
                sourceId: $activity->getId(),
                targetId: null,
                status: SyncStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @return callable(string, array<string, mixed>): UpsertResult
     */
    private function buildUpsertFn(string $entityType): callable
    {
        return match ($entityType) {
            'account' => fn (string $lookupField, array $data) => $this->ccAdapter->upsertAccount(
                $lookupField,
                \Daktela\CrmSync\Entity\Account::fromArray($data),
            ),
            'contact' => fn (string $lookupField, array $data) => $this->ccAdapter->upsertContact(
                $lookupField,
                \Daktela\CrmSync\Entity\Contact::fromArray($data),
            ),
            default => throw new \LogicException("Unsupported entity type: {$entityType}"),
        };
    }

    private function findCrmEntity(string $entityType, string $id): ?EntityInterface
    {
        return match ($entityType) {
            'account' => $this->crmAdapter->findAccount($id),
            'contact' => $this->crmAdapter->findContact($id),
            default => null,
        };
    }

    private function ensureCrmEntityInCc(string $entityType, string $crmId): ?RecordResult
    {
        if (isset($this->relationMaps[$entityType][$crmId])) {
            return null;
        }

        $guardKey = $entityType . ':' . $crmId;
        if (isset($this->syncingEntities[$guardKey])) {
            return null;
        }

        $this->syncingEntities[$guardKey] = true;

        try {
            $entity = $this->findCrmEntity($entityType, $crmId);
            if ($entity === null) {
                $this->logger->warning('Cannot auto-create {type} {id}: not found in CRM', [
                    'type' => $entityType,
                    'id' => $crmId,
                ]);
                return null;
            }

            $mapping = $this->config->getMapping($entityType);
            if ($mapping === null) {
                return null;
            }

            $upsertFn = $this->buildUpsertFn($entityType);

            return $this->syncEntityToCc(
                entity: $entity,
                mapping: $mapping,
                entityType: $entityType,
                upsertFn: $upsertFn,
            );
        } finally {
            unset($this->syncingEntities[$guardKey]);
        }
    }

    /**
     * Make sure every entity this one references is present in the relation map,
     * auto-syncing the ones that are missing (docs/03: "How It Works", step 4).
     *
     * @throws MappingException when a referenced entity exists but syncing it
     *   failed. The mapper's fallback for an unresolved value is to pass the raw
     *   CRM foreign key through, which is correct only for the documented case of
     *   an entity that is genuinely absent from the CRM (step 5). When the
     *   resolution attempt itself failed — CRM unreachable, Daktela write
     *   rejected — passing it through writes a raw CRM id into a Daktela relation
     *   field and reports the record as synced. Failing this one record instead
     *   keeps its siblings syncing.
     *
     *   Note what this does NOT do: the record is not retried automatically.
     *   saveState() advances the watermark whenever any record succeeded (see
     *   docs/07), so a failed record falls outside the next incremental window
     *   until its source timestamp changes again or a forced full sync runs. The
     *   choice here is therefore "absent and reported failed" over "present with
     *   a wrong link and reported synced" — not "later" over "now".
     */
    private function ensureMappingRelations(EntityInterface $entity, MappingCollection $mapping): void
    {
        // Only the collection's own rules. Per-type (`types:`) rules are irrelevant
        // here: forType() is applied on the activity path alone, which never calls
        // this method, so its callers (accounts, contacts, and custom entities on
        // the IMPORT side — the export path never calls it) map with
        // the raw collection and FieldMapper never reads a `types:` rule. Resolving
        // them would fail records over rules that produce no output for them.
        foreach ($mapping->mappings as $fieldMapping) {
            $relation = $fieldMapping->relation;
            if ($relation === null) {
                continue;
            }

            $value = $this->fieldMapper->readNestedValue($entity, $fieldMapping->crmField);
            if ($value === null || $value === '') {
                continue;
            }

            if (isset($this->relationMaps[$relation->entity][(string) $value])) {
                continue;
            }

            // A resolution that already failed DETERMINISTICALLY this batch is not
            // retried: without that, every record referencing one unwritable
            // account re-ran findAccount + upsertAccount before failing itself —
            // 10k contacts behind one bad account meant 10k CRM reads, 10k
            // rejected writes and 20k error lines.
            //
            // Cached: the referenced entity came back as a Failed record. Not
            // cached: the LOOKUP threw, which propagates past ensureCrmEntityInCc()
            // and is re-attempted per record — an outage can end mid-batch, and
            // caching it failed every record behind a fault lasting one request.
            //
            // Note the boundary is where the throw happens, not whether the fault
            // is transient: a throw from the CC WRITE is caught inside
            // syncEntityToCc() and returned as a Failed record, so it lands in the
            // cached branch. A one-request write outage therefore does condemn the
            // rest of the batch. That is bounded to a batch by design (the cache is
            // cleared at every batch entry point) and is the price of not paying a
            // lookup per referrer for a genuinely broken relation.
            $failureKey = $relation->entity . '|' . (string) $value;
            if (isset($this->relationFailures[$failureKey])) {
                throw MappingException::relationResolutionFailed(
                    $relation->entity,
                    (string) $value,
                    $this->relationFailures[$failureKey],
                );
            }

            $record = $this->ensureCrmEntityInCc($relation->entity, (string) $value);

            if ($record !== null && $record->status === SyncStatus::Failed) {
                $reason = $record->errorMessage !== '' ? $record->errorMessage : 'unknown error';
                $this->relationFailures[$failureKey] = $reason;

                throw MappingException::relationResolutionFailed($relation->entity, (string) $value, $reason);
            }
        }
    }

    /**
     * Builds a relation map by iterating CRM entities and mapping their IDs
     * to the corresponding Daktela field values.
     */
    private function buildRelationMap(RelationConfig $relation): void
    {
        if (isset($this->relationMaps[$relation->entity])) {
            return; // Already built
        }

        $this->relationMaps[$relation->entity] = [];

        $mapping = $this->config->getMapping($relation->entity);
        if ($mapping === null) {
            return;
        }

        // Find the rule that produces resolve_to by scanning the entity's own
        // mappings. The whole RULE, not just its CRM-side field name: the value
        // this map stores has to be the mapped one (see below).
        $resolveToSourceField = null;
        $identityRule = null;
        foreach ($mapping->mappings as $fm) {
            if ($fm->ccField === $relation->resolveTo) {
                $resolveToSourceField = $fm->crmField; // CRM-side field
                $identityRule = new MappingCollection($mapping->entityType, $mapping->lookupField, [$fm]);
                break;
            }
        }

        if ($resolveToSourceField === null || $identityRule === null) {
            $this->logger->warning('Cannot build relation map for {entity}: resolve_to field "{field}" not found in mappings', [
                'entity' => $relation->entity,
                'field' => $relation->resolveTo,
            ]);
            return;
        }

        $iterator = match ($relation->entity) {
            'account' => $this->crmAdapter->iterateAccounts(),
            'contact' => $this->crmAdapter->iterateContacts(),
            default => null,
        };

        if ($iterator === null) {
            return;
        }

        // The value stored here MUST match what syncEntityToCc() stores for the
        // same entity — the CC record's identity — because both feed the same
        // FieldMapper lookup. Reading the CRM field raw stored the PRE-transform
        // value, so with any transformer on the rule that produces the CC
        // identity (the documented prefix convention, for one) the two disagreed:
        // a contact synced in a run that imported accounts got "pipedrive_org_5",
        // one synced in a run that only built the map got "5", and the second
        // points at an account that does not exist. Apply the rule.
        foreach ($iterator as $entity) {
            $fromValue = $entity->get($relation->resolveFrom);
            if ($fromValue === null) {
                continue;
            }

            $toValue = NestedValue::get(
                $this->fieldMapper->map($entity, $identityRule, SyncDirection::CrmToCc),
                $relation->resolveTo,
            );

            if ($toValue !== null && (string) $toValue !== '') {
                $this->relationMaps[$relation->entity][(string) $fromValue] = (string) $toValue;
            }
        }

        $this->logger->info('Built relation map for {entity}: {count} entries', [
            'entity' => $relation->entity,
            'count' => count($this->relationMaps[$relation->entity]),
        ]);
    }

    private function autoCreateContactFromAccount(
        EntityInterface $account,
        ?string $accountCcId,
    ): ?RecordResult {
        $contactMapping = $this->config->getAutoCreateContactMapping('account');
        if ($contactMapping === null || $accountCcId === null) {
            return null;
        }

        try {
            $mapped = $this->fieldMapper->map(
                $account, $contactMapping, SyncDirection::CrmToCc, $this->relationMaps,
            );

            $mapped['account'] = $accountCcId;

            $entityConfig = $this->config->getEntityConfig('account');
            $autoContactConfig = $entityConfig?->autoCreateContact;
            $skipIfEmpty = $autoContactConfig->skipIfEmpty ?? [];

            if ($skipIfEmpty !== [] && $this->allFieldsEmpty($mapped, $skipIfEmpty)) {
                $this->logger->debug('Skip auto-contact: required fields are empty', [
                    'account' => $accountCcId,
                ]);
                return null;
            }

            $contact = Contact::fromArray($mapped);
            $lookupValue = $contact->get($contactMapping->lookupField);

            if ($lookupValue !== null) {
                $existing = $this->ccAdapter->findContactBy(
                    [$contactMapping->lookupField => (string) $lookupValue],
                );

                if ($existing === null) {
                    $skipFields = $autoContactConfig->skipIfExistsFields ?? [];
                    $skipMode = $autoContactConfig->skipIfExistsMode ?? SkipIfExistsMode::All;

                    if ($this->shouldSkipAutoContact($mapped, $accountCcId, $skipFields, $skipMode)) {
                        $this->logger->debug('Skip auto-contact: existing contact covers info', [
                            'account' => $accountCcId,
                        ]);
                        return null;
                    }
                }
            }

            $upsertResult = $this->ccAdapter->upsertContact($contactMapping->lookupField, $contact);

            $status = match (true) {
                $upsertResult->skipped => SyncStatus::Skipped,
                $upsertResult->created => SyncStatus::Created,
                default => SyncStatus::Updated,
            };

            return new RecordResult(
                entityType: 'contact',
                sourceId: $account->getId(),
                targetId: $upsertResult->entity->getId(),
                status: $status,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to auto-create contact from account {id}: {error}', [
                'id' => $account->getId(),
                'error' => $e->getMessage(),
            ]);

            return new RecordResult(
                entityType: 'contact',
                sourceId: $account->getId(),
                targetId: null,
                status: SyncStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @param array<string, mixed> $mapped     Mapped contact data
     * @param string[]             $skipFields CC field names to check
     */
    private function shouldSkipAutoContact(
        array $mapped,
        string $accountCcId,
        array $skipFields,
        SkipIfExistsMode $mode,
    ): bool {
        if ($skipFields === []) {
            return false;
        }

        if ($mode === SkipIfExistsMode::All) {
            $criteria = ['account' => $accountCcId];
            foreach ($skipFields as $field) {
                $value = NestedValue::get($mapped, $field);
                if ($value === null || $value === '' || $value === []) {
                    return false; // can't match all if a field is empty
                }
                $criteria[$field] = is_array($value) ? (string) reset($value) : (string) $value;
            }

            return $this->ccAdapter->findContactBy($criteria) !== null;
        }

        // Mode: any — skip if any single field matches
        foreach ($skipFields as $field) {
            $value = NestedValue::get($mapped, $field);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $existing = $this->ccAdapter->findContactBy([
                'account' => $accountCcId,
                $field => is_array($value) ? (string) reset($value) : (string) $value,
            ]);

            if ($existing !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $mapped
     * @param string[]             $fields
     */
    private function allFieldsEmpty(array $mapped, array $fields): bool
    {
        foreach ($fields as $field) {
            $value = NestedValue::get($mapped, $field);
            if ($value !== null && $value !== '' && $value !== []) {
                return false;
            }
        }

        return true;
    }

    private function resolveSince(string $entityType): ?\DateTimeImmutable
    {
        if ($this->stateStore === null || $this->forceFullSync) {
            return null;
        }

        return $this->stateStore->getLastSyncTime($entityType);
    }
}
