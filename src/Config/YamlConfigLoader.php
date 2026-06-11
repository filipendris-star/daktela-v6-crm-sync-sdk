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
        $batchSize = (int) ($data['sync']['batch_size'] ?? 100);
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

                $activityTypes = [];
                if (isset($config['activity_types']) && is_array($config['activity_types'])) {
                    foreach ($config['activity_types'] as $at) {
                        $activityType = ActivityType::tryFrom((string) $at);
                        if ($activityType !== null) {
                            $activityTypes[] = $activityType;
                        }
                    }
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

                $direction = SyncDirection::tryFrom((string) ($entry['direction'] ?? ''));
                if ($direction === null) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('Invalid direction for custom entity "%s"', $name),
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

                $initialSync = (string) ($entry['initial_sync'] ?? 'now');
                if (!in_array($initialSync, ['now', 'everything'], true)) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('initial_sync for custom entity "%s" must be "now" or "everything"', $name),
                    );
                }

                $exportFilter = [];
                if (isset($entry['export_filter']) && is_array($entry['export_filter'])) {
                    foreach ($entry['export_filter'] as $filter) {
                        if (!is_array($filter) || !isset($filter['field'])) {
                            throw ConfigurationException::invalidMappingFile(
                                $configPath,
                                sprintf('export_filter entries for custom entity "%s" need field/operator/value', $name),
                            );
                        }
                        $exportFilter[] = [
                            'field' => (string) $filter['field'],
                            'operator' => (string) ($filter['operator'] ?? 'eq'),
                            'value' => $filter['value'] ?? '',
                        ];
                    }
                }

                $writeBack = [];
                if (isset($entry['write_back']) && is_array($entry['write_back'])) {
                    $writeBack = $this->mappingLoader->parseInlineRules(
                        $configPath,
                        $entry['write_back'],
                        sprintf('custom_entities[%s].write_back', $name),
                    );
                }

                $customEntities[] = new CustomEntitySyncConfig(
                    name: $name,
                    enabled: (bool) ($entry['enabled'] ?? false),
                    direction: $direction,
                    source: $source,
                    target: $target,
                    mappingFile: $mappingFile,
                    initialSync: $initialSync,
                    sinceField: (string) ($entry['since_field'] ?? 'edited'),
                    exportFilter: $exportFilter,
                    writeBack: $writeBack,
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
