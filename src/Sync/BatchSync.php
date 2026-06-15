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
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Entity\EntityInterface;
use Daktela\CrmSync\Entity\GenericEntity;
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
        $this->forceFullSync = $force;
    }

    public function resetOffsets(): void
    {
        $this->offsets = [];
        $this->cursors = [];
    }

    /**
     * Resolve the resume cursor for a cursor-paginated entity: in-run cursor first
     * (continues a drain across the engine's batch loop), else the persisted one
     * (resumes a drain that a previous run left unfinished).
     */
    private function resolveCursor(string $key): ?string
    {
        return $this->cursors[$key] ?? $this->stateStore?->getCursor($key);
    }

    /**
     * Record the page outcome: a short page (rows < limit) or a null next token
     * means the drain is complete — mark exhausted and clear the cursor so the
     * next run starts fresh. Otherwise persist the next token (in-run + on disk)
     * so an interrupted drain resumes instead of restarting.
     *
     * @param CursorPage<mixed> $page
     */
    private function advanceCursor(string $key, CursorPage $page, int $rows, int $limit, SyncResult $result): void
    {
        $exhausted = $rows < $limit || $page->nextCursor === null;
        $next = $exhausted ? null : $page->nextCursor;

        $result->setExhausted($exhausted);
        $this->cursors[$key] = $next;
        $this->stateStore?->setCursor($key, $next);
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
            $this->advanceCursor('contact', $page, count($page->records), $limit, $result);
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
            $this->advanceCursor('account', $page, count($page->records), $limit, $result);
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
            $activityConfig = $this->config->getEntityConfig('activity');
            if ($activityConfig === null || $activityConfig->initialSync === 'now') {
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
        $offset = $this->offsets['activity'] ?? 0;
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($activityTypes as $type) {
            $typeMapping = $mapping->forType($type->value);

            foreach ($this->ccAdapter->iterateActivities($type, $since, $offset) as $activity) {
                $record = $this->syncActivityToCrm($activity, $typeMapping);
                $result->addRecord($record);
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
            $this->offsets['activity'] = 0;
        } else {
            $this->offsets['activity'] = $offset + $count;
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
            $this->logger->error(
                'Custom entity "{name}" is cc_to_crm but the CRM adapter does not support custom entity writes — skipping',
                ['name' => $entry->name],
            );
            $result->setExhausted(true);
            $result->finish();

            return $result;
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
        $exhausted = true;

        foreach ($this->ccAdapter->iterateEntity($entry->source, $since, $offset, $entry->exportFilter, $entry->sinceField) as $row) {
            $result->addRecord($this->exportCustomRecordToCrm($entry, $mapping, $row));
            $count++;

            if ($count >= $this->config->batchSize) {
                $exhausted = false;
                break;
            }
        }

        $result->setExhausted($exhausted);
        $result->finish();

        $this->offsets[$offsetKey] = $exhausted ? 0 : $offset + $count;

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
     */
    private function exportCustomRecordToCrm(
        \Daktela\CrmSync\Config\CustomEntitySyncConfig $entry,
        MappingCollection $mapping,
        array $row,
    ): RecordResult {
        $sourceId = isset($row['id']) ? (string) $row['id'] : null;

        try {
            /** @var SupportsCustomEntityWriteInterface&CrmAdapterInterface $writer */
            $writer = $this->crmAdapter;

            $entity = new GenericEntity($sourceId, $row, $entry->source);
            $mapped = $this->fieldMapper->map($entity, $mapping, SyncDirection::CcToCrm, $this->relationMaps);

            $existing = null;
            $lookupValue = $mapped[$mapping->lookupField] ?? null;
            if (is_scalar($lookupValue) && (string) $lookupValue !== '') {
                $existing = $writer->findCustomEntityByLookup($entry->target, $mapping->lookupField, (string) $lookupValue);
            }

            if ($existing !== null && isset($existing['id'])) {
                $targetId = (string) $existing['id'];
                $writer->updateCustomEntity($entry->target, $targetId, $mapped);
                $status = SyncStatus::Updated;
            } else {
                $created = $writer->createCustomEntity($entry->target, $mapped);
                $targetId = isset($created['id']) ? (string) $created['id'] : null;
                $status = SyncStatus::Created;

                if ($entry->writeBack !== [] && $sourceId !== null && $targetId !== null) {
                    $this->applyExportWriteBack($entry, $sourceId, $created);
                }
            }

            return new RecordResult(
                entityType: "custom:{$entry->name}",
                sourceId: $sourceId,
                targetId: $targetId,
                status: $status,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to export {name} record {id}: {error}', [
                'name' => $entry->name,
                'id' => $sourceId,
                'error' => $e->getMessage(),
            ]);

            return new RecordResult(
                entityType: "custom:{$entry->name}",
                sourceId: $sourceId,
                targetId: null,
                status: SyncStatus::Failed,
                errorMessage: $e->getMessage(),
            );
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
    ): void {
        $writeBackMapping = new MappingCollection($entry->source, 'id', $entry->writeBack);
        $crmEntity = new GenericEntity(
            isset($crmRecord['id']) ? (string) $crmRecord['id'] : null,
            $crmRecord,
            $entry->target,
        );

        $mapped = $this->fieldMapper->map($crmEntity, $writeBackMapping, SyncDirection::CrmToCc, $this->relationMaps);
        if ($mapped === []) {
            return;
        }

        match ($entry->source) {
            \Daktela\CrmSync\Config\CustomEntitySyncConfig::TARGET_CONTACT
                => $this->ccAdapter->updateContact($sourceId, Contact::fromArray($mapped)),
            \Daktela\CrmSync\Config\CustomEntitySyncConfig::TARGET_ACCOUNT
                => $this->ccAdapter->updateAccount($sourceId, \Daktela\CrmSync\Entity\Account::fromArray($mapped)),
            default => $this->logger->warning('write_back not supported for source "{source}"', [
                'source' => $entry->source,
            ]),
        };
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
