<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

use Daktela\CrmSync\Config\EnvResolver;
use Daktela\CrmSync\Exception\ConfigurationException;
use Symfony\Component\Yaml\Yaml;

final class YamlMappingLoader
{
    public function load(string $filePath): MappingCollection
    {
        if (!is_file($filePath)) {
            throw ConfigurationException::fileNotFound($filePath);
        }

        /** @var mixed $data */
        $data = Yaml::parseFile($filePath);

        if (is_array($data)) {
            $data = EnvResolver::resolve($data);
        }

        if (!is_array($data)) {
            throw ConfigurationException::invalidMappingFile($filePath, 'File must contain a YAML mapping');
        }

        if (!isset($data['entity']) || !is_string($data['entity'])) {
            throw ConfigurationException::invalidMappingFile($filePath, 'Missing or invalid "entity" key');
        }

        if (!isset($data['lookup_field']) || !is_string($data['lookup_field'])) {
            throw ConfigurationException::invalidMappingFile($filePath, 'Missing or invalid "lookup_field" key');
        }

        $hasLegacy = isset($data['mappings']);
        $hasStructured = isset($data['default']) || isset($data['types']);

        if ($hasLegacy && $hasStructured) {
            throw ConfigurationException::invalidMappingFile(
                $filePath,
                'Use either top-level "mappings" or "default"/"types", not both',
            );
        }

        if (!$hasLegacy && !$hasStructured) {
            throw ConfigurationException::invalidMappingFile($filePath, 'Missing or invalid "mappings" key');
        }

        if ($hasLegacy) {
            $base = $this->parseMappingList($filePath, $data['mappings'], 'mappings');
            $typeMappings = [];
        } else {
            $base = [];
            if (isset($data['default'])) {
                if (!is_array($data['default']) || !is_array($data['default']['mappings'] ?? null)) {
                    throw ConfigurationException::invalidMappingFile(
                        $filePath,
                        '"default" must contain a "mappings" list',
                    );
                }
                $base = $this->parseMappingList($filePath, $data['default']['mappings'], 'default.mappings');
            }

            $typeMappings = [];
            if (isset($data['types'])) {
                if (!is_array($data['types'])) {
                    throw ConfigurationException::invalidMappingFile($filePath, '"types" must be a map of type => rules');
                }
                foreach ($data['types'] as $typeKey => $typeNode) {
                    if (!is_array($typeNode) || !is_array($typeNode['mappings'] ?? null)) {
                        throw ConfigurationException::invalidMappingFile(
                            $filePath,
                            sprintf('"types.%s" must contain a "mappings" list', (string) $typeKey),
                        );
                    }
                    $typeMappings[(string) $typeKey] = $this->parseMappingList(
                        $filePath,
                        $typeNode['mappings'],
                        sprintf('types.%s.mappings', (string) $typeKey),
                    );
                }
            }
        }

        return new MappingCollection(
            entityType: $data['entity'],
            lookupField: $data['lookup_field'],
            mappings: $base,
            typeMappings: $typeMappings,
        );
    }

    /**
     * Parse a list of field mapping rules that lives outside a mapping file
     * (e.g. a custom entity's inline `write_back` rules in sync.yaml).
     *
     * @param string $origin used in error messages (config path or description)
     * @param mixed $list
     * @return FieldMapping[]
     */
    public function parseInlineRules(string $origin, mixed $list, string $context = 'rules'): array
    {
        return $this->parseMappingList($origin, $list, $context);
    }

    /**
     * @param mixed $list
     * @return FieldMapping[]
     */
    private function parseMappingList(string $filePath, mixed $list, string $context): array
    {
        if (!is_array($list)) {
            throw ConfigurationException::invalidMappingFile(
                $filePath,
                sprintf('"%s" must be a list', $context),
            );
        }

        $mappings = [];
        foreach ($list as $index => $item) {
            if (!is_array($item)) {
                throw ConfigurationException::invalidMappingFile(
                    $filePath,
                    sprintf('Mapping at %s[%d] must be an array', $context, $index),
                );
            }

            $mappings[] = $this->parseFieldMapping($filePath, (int) $index, $item);
        }

        return $mappings;
    }

    /**
     * @param int $index
     * @param array<string, mixed> $item
     */
    private function parseFieldMapping(string $filePath, int $index, array $item): FieldMapping
    {
        if (!isset($item['cc_field']) || !is_string($item['cc_field'])) {
            throw ConfigurationException::invalidMappingFile(
                $filePath,
                sprintf('Mapping at index %d: missing or invalid "cc_field"', $index),
            );
        }

        $hasStaticValue = array_key_exists('value', $item);

        if (!$hasStaticValue && (!isset($item['crm_field']) || !is_string($item['crm_field']))) {
            throw ConfigurationException::invalidMappingFile(
                $filePath,
                sprintf('Mapping at index %d: missing or invalid "crm_field" (or provide "value" for a static value)', $index),
            );
        }

        $transformers = [];
        if (isset($item['transformers']) && is_array($item['transformers'])) {
            foreach ($item['transformers'] as $t) {
                if (!is_array($t) || !isset($t['name'])) {
                    throw ConfigurationException::invalidMappingFile(
                        $filePath,
                        sprintf('Mapping at index %d: invalid transformer definition', $index),
                    );
                }
                $transformers[] = [
                    'name' => (string) $t['name'],
                    'params' => is_array($t['params'] ?? null) ? $t['params'] : [],
                ];
            }
        }

        $multiValue = null;
        if (isset($item['multi_value']) && is_array($item['multi_value'])) {
            $strategyStr = (string) ($item['multi_value']['strategy'] ?? '');
            $strategy = MultiValueStrategy::tryFrom($strategyStr);
            if ($strategy === null) {
                throw ConfigurationException::invalidMappingFile(
                    $filePath,
                    sprintf('Mapping at index %d: invalid multi_value strategy "%s"', $index, $strategyStr),
                );
            }
            $multiValue = new MultiValueConfig(
                strategy: $strategy,
                separator: (string) ($item['multi_value']['separator'] ?? ','),
            );
        }

        $relation = null;
        if (isset($item['relation']) && is_array($item['relation'])) {
            $entity = (string) ($item['relation']['entity'] ?? '');
            $resolveFrom = (string) ($item['relation']['resolve_from'] ?? '');
            $resolveTo = (string) ($item['relation']['resolve_to'] ?? '');
            if ($entity === '' || $resolveFrom === '' || $resolveTo === '') {
                throw ConfigurationException::invalidMappingFile(
                    $filePath,
                    sprintf('Mapping at index %d: relation requires entity, resolve_from, and resolve_to', $index),
                );
            }
            $relation = new RelationConfig(
                entity: $entity,
                resolveFrom: $resolveFrom,
                resolveTo: $resolveTo,
            );
        }

        return new FieldMapping(
            ccField: $item['cc_field'],
            crmField: (string) ($item['crm_field'] ?? ''),
            transformers: $transformers,
            multiValue: $multiValue,
            relation: $relation,
            append: (bool) ($item['append'] ?? false),
            staticValue: $hasStaticValue ? $item['value'] : null,
            hasStaticValue: $hasStaticValue,
        );
    }
}
