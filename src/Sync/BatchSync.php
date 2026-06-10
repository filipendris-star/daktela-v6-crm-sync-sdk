<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsDealLinkingInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\SkipIfExistsMode;
use Daktela\CrmSync\Config\SyncConfiguration;
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

    /** @var array<string, int> Tracks pagination offset per entity type */
    private array $offsets = [];

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
        $offset = $this->offsets['contact'] ?? 0;
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($this->crmAdapter->iterateContacts($since, $offset) as $contact) {
            $record = $this->syncEntityToCc(
                entity: $contact,
                mapping: $mapping,
                entityType: 'contact',
                upsertFn: $this->buildUpsertFn('contact'),
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
            $this->offsets['contact'] = 0;
        } else {
            $this->offsets['contact'] = $offset + $count;
        }

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
        $offset = $this->offsets['account'] ?? 0;
        $result = new SyncResult();
        $autoContactResult = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($this->crmAdapter->iterateAccounts($since, $offset) as $account) {
            $record = $this->syncEntityToCc(
                entity: $account,
                mapping: $mapping,
                entityType: 'account',
                upsertFn: $this->buildUpsertFn('account'),
            );

            $result->addRecord($record);

            if ($record->status !== SyncStatus::Failed) {
                $autoRecord = $this->autoCreateContactFromAccount($account, $record->targetId);
                if ($autoRecord !== null) {
                    $autoContactResult->addRecord($autoRecord);
                }
            }

            $count++;

            if ($count >= $this->config->batchSize) {
                $exhausted = false;
                break;
            }
        }

        $result->setExhausted($exhausted);
        $result->finish();
        $autoContactResult->setExhausted($exhausted);
        $autoContactResult->finish();

        if ($exhausted) {
            $this->offsets['account'] = 0;
        } else {
            $this->offsets['account'] = $offset + $count;
        }

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
     * Sync one batch of records from a configured custom entity into its target Daktela entity.
     * Records flow source → target via the per-entry mapping; the target's existing upsert path
     * is reused so relation maps and other downstream logic stay consistent.
     */
    public function syncCustomEntity(
        \Daktela\CrmSync\Config\CustomEntitySyncConfig $entry,
        MappingCollection $mapping,
    ): SyncResult {
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
