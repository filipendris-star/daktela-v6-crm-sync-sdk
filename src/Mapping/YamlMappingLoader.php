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

        // An empty top-level `mappings` ({} / []) is treated as absent — the UI emits
        // an empty `mappings:` key alongside default/types, which must not conflict.
        $hasLegacy = !empty($data['mappings']);
        $hasStructured = !empty($data['default']) || !empty($data['types']);

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
            // Same tolerance as the top-level checks: an empty `default:` ({} / [])
            // emitted by the UI is treated as absent, not as a malformed section.
            if (!empty($data['default'])) {
                if (!is_array($data['default']) || !is_array($data['default']['mappings'] ?? null)) {
                    throw ConfigurationException::invalidMappingFile(
                        $filePath,
                        '"default" must contain a "mappings" list',
                    );
                }
                $base = $this->parseMappingList($filePath, $data['default']['mappings'], 'default.mappings');
            }

            $typeMappings = [];
            if (!empty($data['types'])) {
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

        if ($data['entity'] === 'activity') {
            $this->assertActivityLookupNamesTheCrmSide(
                $filePath,
                $data['lookup_field'],
                $base,
                $typeMappings,
            );
        }

        return new MappingCollection(
            entityType: $data['entity'],
            lookupField: $data['lookup_field'],
            mappings: $base,
            typeMappings: $typeMappings,
        );
    }

    /**
     * Catch the one `lookup_field` mistake a loader CAN see: it names a rule's
     * cc_field while that rule writes a DIFFERENT crm_field.
     *
     * Activities are export-only (cc_to_crm), so `lookup_field` is read against the
     * mapped CRM payload — it has to name a crm_field. Naming the cc_field is the
     * natural mistake, and the SDK's own example shipped it until 1.2.0
     * (`lookup_field: name` against `crm_field: external_id`), so every config
     * derived from that example carries it.
     *
     * Left to run time it surfaces as an aborted activity step on every run, with a
     * message that can only say the value is missing. Here it can say what to use
     * instead.
     *
     * Deliberately narrow. It fires only when the name is unambiguously the wrong
     * side of a rule that exists; every other way of getting `lookup_field` wrong (a
     * dotted path, a static rule, a value that does not vary per record) is invisible
     * to a loader and stays with the write-time checks in BatchSync/WebhookSync.
     *
     * @param FieldMapping[] $base
     * @param array<string, FieldMapping[]> $typeMappings
     */
    private function assertActivityLookupNamesTheCrmSide(
        string $filePath,
        string $lookupField,
        array $base,
        array $typeMappings,
    ): void {
        $allRules = $base;
        foreach ($typeMappings as $typeRules) {
            foreach ($typeRules as $rule) {
                $allRules[] = $rule;
            }
        }

        // A rule that WRITES this name settles it: the payload will carry the key,
        // wherever else the name also appears.
        foreach ($allRules as $rule) {
            if ($rule->crmField === $lookupField) {
                return;
            }
        }

        foreach ($allRules as $rule) {
            if ($rule->ccField === $lookupField && $rule->crmField !== '') {
                throw ConfigurationException::invalidMappingFile(
                    $filePath,
                    sprintf(
                        'lookup_field "%s" names a cc_field. An activity mapping exports to the CRM, '
                        . 'so lookup_field must name the CRM-side field that carries the Daktela '
                        . 'activity id — otherwise every export re-creates the record instead of '
                        . 'updating it. Did you mean "%s"?',
                        $lookupField,
                        $rule->crmField,
                    ),
                );
            }
        }
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

        $transformers = $this->parseTransformers($item['transformers'] ?? null, $filePath, $index);

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
                transformers: $this->parseTransformers(
                    $item['multi_value']['transformers'] ?? null,
                    $filePath,
                    $index,
                ),
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

    /**
     * Parse and validate a transformer list. Shared by a rule's own transformers
     * and by those under multi_value, so a date_format buried in a multi_value
     * block gets the same load-time timezone check as one on the rule itself —
     * the check exists precisely because failing later fails only SOME records,
     * which advances the watermark past the ones it dropped.
     *
     * @return list<array{name: string, params: array<string, mixed>}>
     */
    private function parseTransformers(mixed $raw, string $filePath, int $index): array
    {
        $transformers = [];
        if (!is_array($raw)) {
            return $transformers;
        }

        foreach ($raw as $t) {
            if (!is_array($t) || !isset($t['name'])) {
                throw ConfigurationException::invalidMappingFile(
                    $filePath,
                    sprintf('Mapping at index %d: invalid transformer definition', $index),
                );
            }
            $params = is_array($t['params'] ?? null) ? $t['params'] : [];

            // Timezone names are validated HERE, not at transform time. An
            // unknown name makes DateTimeZone throw, and because
            // DateFormatTransformer returns early for an empty value, that
            // Error would fail only the records that actually carry a date —
            // a PARTIAL batch failure, which advances the watermark past the
            // very records it dropped. One typo, permanent silent loss. Same
            // rule the rest of the config follows: reject at load.
            foreach (['from_tz', 'to_tz'] as $tzKey) {
                if (!isset($params[$tzKey])) {
                    continue;
                }
                // Cast inside the guarded region: a non-scalar value (`to_tz: [a, b]`)
                // raises "Array to string conversion", which a host that promotes
                // warnings to ErrorException would surface instead of the
                // ConfigurationException the rest of this loader guarantees.
                if (!is_string($params[$tzKey]) && !is_numeric($params[$tzKey])) {
                    throw ConfigurationException::invalidMappingFile(
                        $filePath,
                        sprintf('Mapping at index %d: "%s" must be a timezone name', $index, $tzKey),
                    );
                }
                $tz = (string) $params[$tzKey];
                // Ask DateTimeZone, do not compare against listIdentifiers():
                // that lists only canonical IANA names, while the constructor
                // also accepts abbreviations and offsets (CET, GMT, +02:00).
                // Matching the list would reject configs that work today.
                try {
                    new \DateTimeZone($tz);
                } catch (\Throwable) {
                    throw ConfigurationException::invalidMappingFile(
                        $filePath,
                        sprintf('Mapping at index %d: unknown timezone "%s" for "%s"', $index, $tz, $tzKey),
                    );
                }
            }

            $transformers[] = [
                'name' => (string) $t['name'],
                'params' => $params,
            ];
        }

        return $transformers;
    }
}
