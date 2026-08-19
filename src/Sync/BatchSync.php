<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsCursorPaginationInterface;
use Daktela\CrmSync\Adapter\SupportsCustomEntityWriteInterface;
use Daktela\CrmSync\Adapter\SupportsDealLinkingInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\SkipIfExistsMode;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Exception\AdapterException;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Exception\NotSupportedException;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Entity\EntityInterface;
use Daktela\CrmSync\Entity\GenericEntity;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\NestedValue;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\State\SyncLedgerInterface;
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

    /** @var array<string, int> Tracks pagination offset per entity type (offset adapters) */
    private array $offsets = [];

    /** @var array<string, string|null> In-run resume cursor per key (cursor adapters) */
    private array $cursors = [];

    /** @var array<string, string|null> "offset:firstRowId" of the previous export batch per key (write-back spin guard) */
    private array $exportSpinGuard = [];

    /** @var array<string, int> Consecutive empty-but-tokened cursor pages per key (adapter spin guard) */
    private array $emptyCursorPages = [];

    /** Consecutive empty cursor pages tolerated before the drain is treated as an adapter fault. */
    private const MAX_EMPTY_CURSOR_PAGES = 50;

    private bool $forceFullSync = false;

    private ?SyncLedgerInterface $ledger = null;

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
        $this->forceFullSync = $force;
    }

    public function setLedger(?SyncLedgerInterface $ledger): void
    {
        $this->ledger = $ledger;
    }

    public function resetOffsets(): void
    {
        $this->offsets = [];
        $this->cursors = [];
        $this->exportSpinGuard = [];
        $this->emptyCursorPages = [];
    }

    /**
     * Resolve the resume cursor for a cursor-paginated entity: in-run cursor first
     * (continues a drain across the engine's batch loop), else the persisted one
     * (resumes a drain that a previous run left unfinished). A forced full re-sync
     * ignores the persisted token — it promises to start over, so resuming a stale
     * mid-drain position would skip everything before it.
     */
    private function resolveCursor(string $key): ?string
    {
        if (array_key_exists($key, $this->cursors)) {
            return $this->cursors[$key];
        }

        if ($this->forceFullSync) {
            return null;
        }

        return $this->stateStore?->getCursor($key);
    }

    /**
     * Record the page outcome: only a null next token (or an empty page) means the
     * drain is complete — mark exhausted and clear the cursor so the next run
     * starts fresh. A short page is NOT treated as exhaustion: filtered searches
     * (e.g. HubSpot) legitimately return fewer rows than the limit while more
     * pages remain, and ending the drain there would strand the rest outside the
     * next incremental window. Otherwise persist the next token (in-run + on
     * disk) so an interrupted drain resumes instead of restarting.
     *
     * @param CursorPage<mixed> $page
     */
    private function advanceCursor(string $key, CursorPage $page, int $rows, SyncResult $result): void
    {
        // Only a null next token ends the drain. An EMPTY page with a live token
        // is the degenerate case of the short-page rule (a scanned page whose
        // rows were all filtered out server-side): ending there would clear the
        // cursor and let the watermark advance over everything behind that token.
        // Bounded so a faulty adapter handing back an endless stream of empty
        // tokened pages fails loudly instead of spinning forever.
        if ($rows === 0 && $page->nextCursor !== null) {
            $seen = ($this->emptyCursorPages[$key] ?? 0) + 1;
            $this->emptyCursorPages[$key] = $seen;

            if ($seen > self::MAX_EMPTY_CURSOR_PAGES) {
                throw AdapterException::cursorPaginationStalled($key, $seen);
            }
        } else {
            unset($this->emptyCursorPages[$key]);
        }

        $exhausted = $page->nextCursor === null;
        $next = $exhausted ? null : $page->nextCursor;

        $result->setExhausted($exhausted);
        $this->cursors[$key] = $next;
        // A forced full drain runs with since = null, so its mid-drain tokens are
        // bound to that query — persisting one would make an interrupted forced
        // run's token resume a *normal* (since-bound) run in the wrong window.
        // Persist only the clear; the in-run cursor still drives forced paging.
        $this->stateStore?->setCursor($key, $this->forceFullSync ? null : $next);
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
            $this->advanceCursor('contact', $page, count($page->records), $result);
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
            $page = $this->crmAdapter->fetchAccountsPage($since, $this->resolveCursor('account'), $limit);
            foreach ($page->records as $account) {
                $processAccount($account);
            }
            $this->advanceCursor('account', $page, count($page->records), $result);
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
                $this->logger->info('Activity sync has no cursor — seeding to now; historical activities are not pushed (initial_sync: now)', [
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
                $ccId = (string) $activity->getId();

                // Idempotency ledger: skip activities already exported (the CRM
                // can't dedupe activities server-side) and record the rest once
                // created. syncActivityToCrm() creates without a CRM-side lookup
                // when a ledger is set, since the ledger already owns dedup.
                if ($this->ledger !== null && $ccId !== '') {
                    $record = $this->exportActivityViaLedger($activity, $typeMapping, $ccId);
                } else {
                    $record = $this->syncActivityToCrm($activity, $typeMapping);
                }
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
     * Ledger-guarded activity export.
     *
     * A ledger READ failure aborts the run: nothing external has happened yet,
     * and failing just the record would let a mixed batch advance the watermark
     * past an activity that was never created — permanent loss. Aborting keeps
     * the watermark, so the next run retries cleanly (already-created activities
     * are deduped by the ledger).
     *
     * A ledger WRITE failure after a successful CRM create is surfaced as a
     * Failed record instead: the CRM record exists, so aborting buys nothing,
     * but operators must see that dedup protection is compromised — if the
     * watermark does not advance (e.g. an all-failed run), the unrecorded
     * create will duplicate on retry.
     */
    private function exportActivityViaLedger(Activity $activity, MappingCollection $typeMapping, string $ccId): RecordResult
    {
        assert($this->ledger !== null);

        try {
            if ($this->ledger->hasSynced('activity', $ccId)) {
                return new RecordResult('activity', $ccId, null, SyncStatus::Skipped);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Activity ledger read failed for {id} — aborting run so the window is not advanced: {error}', ['id' => $ccId, 'error' => $e->getMessage()]);

            throw $e;
        }

        $record = $this->syncActivityToCrm($activity, $typeMapping);

        if ($record->status !== SyncStatus::Failed) {
            try {
                $this->ledger->recordSynced('activity', $ccId, $record->targetId);
            } catch (\Throwable $e) {
                $this->logger->error('Activity ledger write failed for {id} (CRM id {crm}): {error}', [
                    'id' => $ccId,
                    'crm' => $record->targetId,
                    'error' => $e->getMessage(),
                ]);

                return new RecordResult('activity', $ccId, $record->targetId, SyncStatus::Failed, errorMessage: 'created in CRM but ledger write failed: ' . $e->getMessage());
            }
        }

        return $record;
    }

    /**
     * Sync one batch of records for a configured custom entity, in the entry's direction:
     * crm_to_cc imports CRM records into a Daktela entity (the original behavior);
     * cc_to_crm exports Daktela records into a CRM-side object (requires the CRM
     * adapter to implement SupportsCustomEntityWriteInterface).
     */
    public function syncCustomEntity(
        \Daktela\CrmSync\Config\CustomEntitySyncConfig $entry,
        MappingCollection $mapping,
    ): SyncResult {
        if ($entry->direction === SyncDirection::CcToCrm) {
            return $this->syncCustomEntityToCrm($entry, $mapping);
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
     * cc_to_crm custom entity export: enumerate a Daktela entity and upsert the
     * records into a CRM-side object.
     *
     * Safety rails:
     *  - first run with no cursor seeds it to now (initial_sync: "now", the default)
     *    instead of exporting full history;
     *  - the entry's export_filter is pushed into the CC query, so excluded records
     *    (e.g. CRM-originated ones) never reach the CRM at all;
     *  - upserts dedup via the CRM lookup (mapping lookup_field) before creating;
     *  - after a create, the entry's write_back rules stamp the CRM identity onto
     *    the CC source record so it is never exported again.
     */
    private function syncCustomEntityToCrm(
        \Daktela\CrmSync\Config\CustomEntitySyncConfig $entry,
        MappingCollection $mapping,
    ): SyncResult {
        $offsetKey = "custom:{$entry->name}";
        $result = new SyncResult();

        if (!$this->crmAdapter instanceof SupportsCustomEntityWriteInterface) {
            // Must abort, not skip: a clean empty result lets saveState() advance
            // the entity's watermark on every run, so once the adapter gains the
            // capability, everything edited while it was missing sits outside the
            // incremental window forever.
            $this->logger->error(
                'Custom entity "{name}" is cc_to_crm but the CRM adapter does not support custom entity writes',
                ['name' => $entry->name],
            );

            throw NotSupportedException::operationNotSupported(
                $this->crmAdapter::class,
                sprintf('custom entity export for "%s" (SupportsCustomEntityWriteInterface)', $entry->name),
            );
        }

        $since = $this->resolveSince($offsetKey);

        if ($since === null && !$this->forceFullSync && $this->stateStore !== null && $entry->initialSync === 'now') {
            $seed = new \DateTimeImmutable();
            $this->stateStore->setLastSyncTime($offsetKey, $seed);
            $this->logger->info(
                'Custom entity "{name}" export has no cursor — seeding to now; historical records are not pushed (initial_sync: now)',
                ['name' => $entry->name, 'seeded' => $seed->format('c')],
            );
            $result->setExhausted(true);
            $result->finish();

            return $result;
        }

        $offset = $this->offsets[$offsetKey] ?? 0;
        $count = 0;
        $stillMatching = 0;
        $firstId = null;
        $exhausted = true;
        // Cap one export batch at the CC adapter's page size (read from the
        // instance so a redeclared constant is honored): the adapter pages
        // internally, and consuming past its first page while write-backs shrink
        // the filtered set makes the second page's skip land past unread rows —
        // permanent loss once the window advances. The cap keeps all pagination
        // under the departure-aware offset below.
        $batchLimit = min($this->config->batchSize, $this->ccAdapter::ITERATE_PAGE_SIZE);

        foreach ($this->ccAdapter->iterateEntity($entry->source, $since, $offset, $entry->exportFilter, $entry->sinceField) as $row) {
            $firstId ??= isset($row['id']) ? (string) $row['id'] : '';

            [$record, $departed] = $this->exportCustomRecordToCrm($entry, $mapping, $row);
            $result->addRecord($record);

            // The export_filter is pushed into the CC query, and a successful
            // write-back removes the record from that filtered set. Rows that
            // departed shrink the set underneath the offset, so advancing by the
            // full batch count would skip unread rows that slid down into this
            // window — and once lastSyncTime advances they are lost for good.
            // Only rows that still match (failures, no write-back) consume offset.
            // NOTE: id-less rows can't be spin-guarded (see below); they also never
            // depart (write-back needs a source id), so they always consume offset.
            if (!$departed) {
                $stillMatching++;
            }

            $count++;

            if ($count >= $batchLimit) {
                $exhausted = false;
                break;
            }
        }

        $result->setExhausted($exhausted);
        $result->finish();

        // Spin guard: seeing the same first row again at the same offset means the
        // write-back "succeeded" without actually removing records from the
        // export_filter (it writes fields the filter doesn't check) — a
        // configuration error. Abort the drain: any forward skip here would step
        // over rows that genuinely departed mid-batch and slid down (permanent
        // loss once the window advances), and continuing would re-serve the same
        // batch forever. Throwing keeps the watermark (saveState never runs), so
        // nothing is lost and the operator sees the error every run until the
        // write_back/export_filter pairing is fixed. The guard binds offset AND
        // row id (a repeat id at a different offset is legitimate movement), and
        // ignores id-less rows.
        $guardValue = ($firstId !== null && $firstId !== '') ? $offset . ':' . $firstId : null;
        if (!$exhausted && $guardValue !== null
            && ($this->exportSpinGuard[$offsetKey] ?? null) === $guardValue && $stillMatching < $count) {
            $this->logger->error(
                'Custom entity "{name}" write_back does not remove records from export_filter — aborting the drain; fix the write_back/export_filter pairing',
                ['name' => $entry->name],
            );

            throw \Daktela\CrmSync\Exception\ConfigurationException::writeBackFilterMismatch($entry->name);
        }
        // Only a non-exhausted batch can be the "previous batch" of a legitimate
        // spin comparison. Storing the guard on an exhausted batch would leave a
        // stale "offset:id" behind after the drain completes, and a later drain
        // starting with the same (e.g. persistently failing) first row would
        // false-match it.
        $this->exportSpinGuard[$offsetKey] = $exhausted ? null : $guardValue;

        $this->offsets[$offsetKey] = $exhausted ? 0 : $offset + $stillMatching;

        $this->logger->info('Batch custom entity {name} export completed (source: {source}, target: {target})', [
            'name' => $entry->name,
            'source' => $entry->source,
            'target' => $entry->target,
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'failed' => $result->getFailedCount(),
            'incremental' => $since !== null,
        ]);

        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{0: RecordResult, 1: bool} [record, departed] — departed is true
     *         when the write-back rewrote the CC record, i.e. by convention the
     *         record no longer matches the export_filter and has left the
     *         filtered result set (the offset bookkeeping depends on this).
     */
    private function exportCustomRecordToCrm(
        \Daktela\CrmSync\Config\CustomEntitySyncConfig $entry,
        MappingCollection $mapping,
        array $row,
    ): array {
        $sourceId = isset($row['id']) ? (string) $row['id'] : null;
        $departed = false;

        try {
            /** @var SupportsCustomEntityWriteInterface&CrmAdapterInterface $writer */
            $writer = $this->crmAdapter;

            $entity = new GenericEntity($sourceId, $row, $entry->source);
            $mapped = $this->fieldMapper->map($entity, $mapping, SyncDirection::CcToCrm, $this->relationMaps);

            $existing = null;
            // NOTE: on export the lookup key addresses the *mapped* (CRM-side)
            // payload — lookup_field must name the CRM field here, unlike imports
            // where it names the CC field. Nested-aware so dotted lookup fields
            // (custom-field paths) resolve instead of silently skipping the
            // existence check and duplicating records on every run.
            $lookupValue = NestedValue::get($mapped, $mapping->lookupField);
            if (is_scalar($lookupValue) && (string) $lookupValue !== '') {
                $existing = $writer->findCustomEntityByLookup($entry->target, $mapping->lookupField, (string) $lookupValue);
            }

            if ($existing !== null && isset($existing['id'])) {
                $targetId = (string) $existing['id'];
                $writer->updateCustomEntity($entry->target, $targetId, $mapped);
                $status = SyncStatus::Updated;

                // The record still matched the export filter even though its CRM
                // counterpart exists — a previous run's write-back must have
                // failed after the create. Retry it here so the record finally
                // leaves the export window instead of re-processing forever.
                if ($entry->writeBack !== [] && $sourceId !== null) {
                    $departed = $this->applyExportWriteBack($entry, $sourceId, $existing);
                }
            } else {
                $created = $writer->createCustomEntity($entry->target, $mapped);
                $targetId = isset($created['id']) ? (string) $created['id'] : null;
                $status = SyncStatus::Created;

                if ($entry->writeBack !== [] && $sourceId !== null && $targetId !== null) {
                    $departed = $this->applyExportWriteBack($entry, $sourceId, $created);
                }
            }

            return [new RecordResult(
                entityType: "custom:{$entry->name}",
                sourceId: $sourceId,
                targetId: $targetId,
                status: $status,
            ), $departed];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to export {name} record {id}: {error}', [
                'name' => $entry->name,
                'id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            return [new RecordResult(
                entityType: "custom:{$entry->name}",
                sourceId: $sourceId,
                targetId: null,
                status: SyncStatus::Failed,
                errorMessage: $e->getMessage(),
            ), false];
        }
    }

    /**
     * Apply the entry's write_back rules: map fields of the freshly created CRM
     * record (CRM→CC) and write them onto the CC source record — typically the
     * CRM id wrapped in the import convention's prefix, so the record joins the
     * regular import flow and the export filter excludes it from now on.
     *
     * @param array<string, mixed> $crmRecord
     */
    private function applyExportWriteBack(
        \Daktela\CrmSync\Config\CustomEntitySyncConfig $entry,
        string $sourceId,
        array $crmRecord,
    ): bool {
        $writeBackMapping = new MappingCollection($entry->source, 'id', $entry->writeBack);
        $crmEntity = new GenericEntity(
            isset($crmRecord['id']) ? (string) $crmRecord['id'] : null,
            $crmRecord,
            $entry->target,
        );

        $mapped = $this->fieldMapper->map($crmEntity, $writeBackMapping, SyncDirection::CrmToCc, $this->relationMaps);
        if ($mapped === []) {
            return false;
        }

        switch ($entry->source) {
            case \Daktela\CrmSync\Config\CustomEntitySyncConfig::TARGET_CONTACT:
                $this->ccAdapter->updateContact($sourceId, Contact::fromArray($mapped));

                return true;
            case \Daktela\CrmSync\Config\CustomEntitySyncConfig::TARGET_ACCOUNT:
                $this->ccAdapter->updateAccount($sourceId, \Daktela\CrmSync\Entity\Account::fromArray($mapped));

                return true;
            default:
                $this->logger->warning('write_back not supported for source "{source}"', [
                    'source' => $entry->source,
                ]);

                return false;
        }
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

    private function syncActivityToCrm(Activity $activity, MappingCollection $mapping): RecordResult
    {
        try {
            $mapped = $this->fieldMapper->map($activity, $mapping, SyncDirection::CcToCrm, $this->relationMaps);
            $mappedActivity = Activity::fromArray($mapped);

            if ($activity->getActivityType() !== null) {
                $mappedActivity->setActivityType($activity->getActivityType());
            }

            $linkDeal = $this->config->getEntityConfig('activity')?->linkDeal;
            if ($linkDeal !== null && $this->crmAdapter instanceof SupportsDealLinkingInterface) {
                $mappedActivity = $this->crmAdapter->linkActivityToDeal($mappedActivity, $linkDeal);
            }

            // With a ledger the existence check already happened (this record is
            // new), so create directly — no CRM-side lookup. Without one, fall
            // back to the adapter's upsert (find-then-create/update).
            if ($this->ledger !== null) {
                $result = $this->crmAdapter->createActivity($mappedActivity);

                return new RecordResult(
                    entityType: 'activity',
                    sourceId: $activity->getId(),
                    targetId: $result->getId(),
                    status: SyncStatus::Created,
                );
            }

            $result = $this->crmAdapter->upsertActivity($mapping->lookupField, $mappedActivity);

            $wasCreated = $activity->getId() !== $result->getId();

            return new RecordResult(
                entityType: 'activity',
                sourceId: $activity->getId(),
                targetId: $result->getId(),
                status: $wasCreated ? SyncStatus::Created : SyncStatus::Updated,
            );
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

    private function ensureMappingRelations(EntityInterface $entity, MappingCollection $mapping): void
    {
        foreach ($mapping->mappings as $fieldMapping) {
            if ($fieldMapping->relation === null) {
                continue;
            }

            $value = $this->fieldMapper->readNestedValue($entity, $fieldMapping->crmField);
            if ($value === null || $value === '') {
                continue;
            }

            if (!isset($this->relationMaps[$fieldMapping->relation->entity][(string) $value])) {
                $this->ensureCrmEntityInCc($fieldMapping->relation->entity, (string) $value);
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

        // Find what CC field maps to resolve_to by scanning the entity's own mappings
        $resolveToSourceField = null;
        foreach ($mapping->mappings as $fm) {
            if ($fm->ccField === $relation->resolveTo) {
                $resolveToSourceField = $fm->crmField; // CRM-side field
                break;
            }
        }

        if ($resolveToSourceField === null) {
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

        foreach ($iterator as $entity) {
            $fromValue = $entity->get($relation->resolveFrom);
            $toValue = $entity->get($resolveToSourceField);

            if ($fromValue !== null && $toValue !== null) {
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
