<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\YamlMappingLoader;
use Daktela\CrmSync\Sync\SyncDirection;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader
{
    public function __construct(
        private readonly YamlMappingLoader $mappingLoader = new YamlMappingLoader(),
    ) {
    }

    /**
     * Parses the YAML config file and resolves ${ENV_VAR} placeholders,
     * returning the full data array. CRM adapters can use this to read their
     * own sections from the shared config file.
     *
     * @return array<string, mixed>
     */
    public function loadRaw(string $configPath): array
    {
        if (!is_file($configPath)) {
            throw ConfigurationException::fileNotFound($configPath);
        }

        /** @var mixed $raw */
        $raw = Yaml::parseFile($configPath);

        if (!is_array($raw)) {
            throw ConfigurationException::invalidMappingFile($configPath, 'Config must be a YAML mapping');
        }

        return $this->resolveEnvVars($raw);
    }

    public function load(string $configPath): SyncConfiguration
    {
        $data = $this->loadRaw($configPath);
        $configDir = dirname($configPath);

        $instanceUrl = (string) ($data['daktela']['instance_url'] ?? '');
        $accessToken = (string) ($data['daktela']['access_token'] ?? '');
        $database = (string) ($data['daktela']['database'] ?? '');
        // A batch size below 1 degrades every drain to one record per batch.
        $batchSize = (int) ($data['sync']['batch_size'] ?? 100);
        if ($batchSize < 1) {
            throw ConfigurationException::invalidMappingFile(
                $configPath,
                sprintf('sync.batch_size must be at least 1, got %d', $batchSize),
            );
        }
        $webhookSecret = (string) ($data['webhook']['secret'] ?? '');

        $entities = [];
        $mappings = [];
        $autoCreateContactMappings = [];

        $entityConfigs = $data['sync']['entities'] ?? [];
        if (is_array($entityConfigs)) {
            foreach ($entityConfigs as $type => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $direction = SyncDirection::tryFrom((string) ($config['direction'] ?? ''));
                if ($direction === null) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('Invalid direction for entity "%s"', $type),
                    );
                }

                // Reject, don't drop. A dropped type leaves activityTypes empty,
                // and syncActivities() then iterates nothing, reports an exhausted
                // 0-record success and advances the watermark on every run — so a
                // typo silently stops the export forever and only forceFullSync
                // recovers it. The values are lowercase while the API filter is
                // uppercased by the adapter, which makes `[CALL]` a natural and
                // previously silent mistake. activity_type_map below already
                // throws for the same unknown type; this is the field that
                // actually gates what gets read.
                $activityTypes = [];
                if (isset($config['activity_types']) && is_array($config['activity_types'])) {
                    foreach ($config['activity_types'] as $at) {
                        $activityType = ActivityType::tryFrom((string) $at);
                        if ($activityType === null) {
                            throw ConfigurationException::invalidMappingFile(
                                $configPath,
                                sprintf(
                                    'activity_types: unknown activity type "%s" for entity "%s" (expected one of: %s)',
                                    (string) $at,
                                    $type,
                                    implode(', ', array_column(ActivityType::cases(), 'value')),
                                ),
                            );
                        }

                        $activityTypes[] = $activityType;
                    }
                }

                // And the list must not be EMPTY, for the same reason unknown
                // values are rejected: syncActivities() resolves a configured
                // entity's types from the config (the [call] fallback applies only
                // when there is no entity config at all), so an absent or empty
                // list makes it iterate nothing, report an exhausted 0-record
                // success and advance the watermark on every run — the export
                // silently never happens and only forceFullSync recovers it.
                if ($type === 'activity' && (bool) ($config['enabled'] ?? false) && $activityTypes === []) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf(
                            'activity_types is required for an enabled activity entity (expected one or more of: %s)',
                            implode(', ', array_column(ActivityType::cases(), 'value')),
                        ),
                    );
                }

                $autoCreateContact = null;
                if (isset($config['auto_create_contact']) && is_array($config['auto_create_contact'])) {
                    $acFile = (string) ($config['auto_create_contact']['mapping_file'] ?? '');
                    $skipFields = (array) ($config['auto_create_contact']['skip_if_exists'] ?? []);
                    $skipMode = SkipIfExistsMode::tryFrom(
                        (string) ($config['auto_create_contact']['skip_if_exists_mode'] ?? ''),
                    ) ?? SkipIfExistsMode::All;
                    $skipIfEmpty = (array) ($config['auto_create_contact']['skip_if_empty'] ?? []);
                    $autoCreateContact = new AutoCreateContactConfig($acFile, $skipFields, $skipMode, $skipIfEmpty);

                    if ($acFile !== '') {
                        $autoCreateContactMappings[(string) $type] = $this->mappingLoader->load(
                            $configDir . '/' . $acFile,
                        );
                    }
                }

                $mappingFile = (string) ($config['mapping_file'] ?? '');

                $activityTypeMap = [];
                if (isset($config['activity_type_map']) && is_array($config['activity_type_map'])) {
                    foreach ($config['activity_type_map'] as $ccType => $crmType) {
                        if (ActivityType::tryFrom((string) $ccType) === null) {
                            throw ConfigurationException::invalidMappingFile(
                                $configPath,
                                sprintf('activity_type_map: unknown CC activity type "%s"', (string) $ccType),
                            );
                        }
                        $activityTypeMap[(string) $ccType] = (string) $crmType;
                    }
                }

                $linkDeal = null;
                if (isset($config['link_deal']) && (string) $config['link_deal'] !== '') {
                    $linkDeal = (string) $config['link_deal'];
                }

                $initialSync = (string) ($config['initial_sync'] ?? 'now');
                if (!in_array($initialSync, ['now', 'everything'], true)) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('initial_sync for entity "%s" must be "now" or "everything"', $type),
                    );
                }

                $entities[(string) $type] = new EntitySyncConfig(
                    enabled: (bool) ($config['enabled'] ?? false),
                    direction: $direction,
                    mappingFile: $mappingFile,
                    activityTypes: $activityTypes,
                    autoCreateContact: $autoCreateContact,
                    activityTypeMap: $activityTypeMap,
                    linkDeal: $linkDeal,
                    initialSync: $initialSync,
                );

                if ($mappingFile !== '') {
                    $fullPath = $configDir . '/' . $mappingFile;
                    $mappings[(string) $type] = $this->mappingLoader->load($fullPath);
                }
            }
        }

        $customEntities = [];
        /** @var array<string, true> guards duplicate slot names (see below) */
        $seenCustomNames = [];
        $customEntityMappings = [];

        $customEntityConfigs = $data['sync']['custom_entities'] ?? [];
        if (is_array($customEntityConfigs)) {
            // No closed set for `target` here — BatchSync validates at sync time so adding new
            // platform targets (e.g. activity) doesn't require touching this loader.
            foreach ($customEntityConfigs as $i => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $name = (string) ($entry['name'] ?? '');
                if ($name === '') {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('custom_entities[%s] missing required "name"', (string) $i),
                    );
                }

                // The name is the slot key for the mapping AND for the state /
                // offset key ("custom:<name>"). Two entries sharing it means one
                // is synced with the other's rules and they share one watermark.
                // Checked before any mapping file is read, so a config error is
                // reported as one rather than as a missing file.
                if (isset($seenCustomNames[$name])) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('custom_entities: duplicate name "%s" — names are slot keys and must be unique', $name),
                    );
                }
                $seenCustomNames[$name] = true;

                // Only crm_to_cc is implemented for custom entities in this
                // release. `bidirectional` and `cc_to_crm` both parse (the enum
                // has them, for first-class entities) but neither has a handler
                // here, and an entry that fell through to the import branch died
                // naming the CRM resource rather than the real problem.
                $direction = SyncDirection::tryFrom((string) ($entry['direction'] ?? ''));
                if ($direction === null) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('Invalid direction for custom entity "%s"', $name),
                    );
                }

                // Only an ENABLED entry is held to the supported direction. A
                // disabled leftover is inert — it was accepted before and never
                // ran — so failing the load for it would take contacts, accounts
                // and activities down with it, which is a far wider blast radius
                // than the entry deserves.
                if ((bool) ($entry['enabled'] ?? false) && $direction !== SyncDirection::CrmToCc) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf(
                            'Invalid direction for custom entity "%s" — only "crm_to_cc" is supported',
                            $name,
                        ),
                    );
                }

                $source = (string) ($entry['source'] ?? '');
                if ($source === '') {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('custom_entities[%s] missing required "source"', $name),
                    );
                }

                $target = (string) ($entry['target'] ?? '');
                if ($target === '') {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('custom_entities[%s] missing required "target"', $name),
                    );
                }

                $mappingFile = (string) ($entry['mapping_file'] ?? '');

                $customEntities[] = new CustomEntitySyncConfig(
                    name: $name,
                    enabled: (bool) ($entry['enabled'] ?? false),
                    direction: $direction,
                    source: $source,
                    target: $target,
                    mappingFile: $mappingFile,
                );

                if ($mappingFile !== '') {
                    $customEntityMappings[$name] = $this->mappingLoader->load($configDir . '/' . $mappingFile);
                }
            }
        }

        return new SyncConfiguration(
            instanceUrl: $instanceUrl,
            accessToken: $accessToken,
            database: $database,
            batchSize: $batchSize,
            entities: $entities,
            mappings: $mappings,
            webhookSecret: $webhookSecret,
            autoCreateContactMappings: $autoCreateContactMappings,
            customEntities: $customEntities,
            customEntityMappings: $customEntityMappings,
        );
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function resolveEnvVars(array $data): array
    {
        return EnvResolver::resolve($data);
    }
}
