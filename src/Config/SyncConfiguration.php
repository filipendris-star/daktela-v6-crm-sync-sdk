<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Mapping\MappingCollection;

final class SyncConfiguration
{
    /**
     * @param array<string, EntitySyncConfig> $entities
     * @param array<string, MappingCollection> $mappings
     * @param array<string, MappingCollection> $autoCreateContactMappings keyed by entity type
     * @param CustomEntitySyncConfig[] $customEntities
     * @param array<string, MappingCollection> $customEntityMappings keyed by CustomEntitySyncConfig::$name
     * @param array<string, string> $entityFaults step key => reason, for entities the loader
     *        REFUSED TO ENABLE because their own config is invalid. See below.
     */
    public function __construct(
        public readonly string $instanceUrl,
        public readonly string $accessToken,
        public readonly string $database,
        public readonly int $batchSize,
        public readonly array $entities,
        public readonly array $mappings,
        public readonly string $webhookSecret = '',
        public readonly array $autoCreateContactMappings = [],
        public readonly array $customEntities = [],
        public readonly array $customEntityMappings = [],
        public readonly array $entityFaults = [],
    ) {
    }

    /**
     * Config faults scoped to a single entity, keyed by the step key SyncEngine
     * reports under ("activity", "custom:orders", …).
     *
     * These are NOT thrown at load. A fault in one entity's config says nothing
     * about the others, and aborting the load for it takes contacts, accounts and
     * activities down together — the exact outcome SyncEngine::runIsolated() exists
     * to prevent for the equivalent fault at RUN time. Aborting the load is reserved
     * for faults that genuinely invalidate the whole file: unparseable YAML, missing
     * credentials, an unreadable mapping file, a batch_size that breaks every drain.
     *
     * The offending entity is left DISABLED so it cannot run half-configured, and the
     * reason is seeded into SyncEngine's step failures, so `hasStepFailures()` — which
     * schedulers already gate on — reports it and the exit code is non-zero. Loud, and
     * survivable.
     *
     * @return array<string, string>
     */
    public function getEntityFaults(): array
    {
        return $this->entityFaults;
    }

    public function getEntityConfig(string $entityType): ?EntitySyncConfig
    {
        return $this->entities[$entityType] ?? null;
    }

    public function getMapping(string $entityType): ?MappingCollection
    {
        return $this->mappings[$entityType] ?? null;
    }

    public function getAutoCreateContactMapping(string $entityType): ?MappingCollection
    {
        return $this->autoCreateContactMappings[$entityType] ?? null;
    }

    public function isEntityEnabled(string $entityType): bool
    {
        $config = $this->getEntityConfig($entityType);

        return $config !== null && $config->enabled;
    }

    /** @return CustomEntitySyncConfig[] */
    public function getEnabledCustomEntities(): array
    {
        return array_values(array_filter($this->customEntities, static fn (CustomEntitySyncConfig $c) => $c->enabled));
    }

    public function getCustomEntityMapping(string $name): ?MappingCollection
    {
        return $this->customEntityMappings[$name] ?? null;
    }
}
