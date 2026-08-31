<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

use Daktela\CrmSync\Entity\EntityInterface;
use Daktela\CrmSync\Exception\MappingException;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;

final class FieldMapper
{
    public function __construct(
        private readonly TransformerRegistry $registry,
    ) {
    }

    /**
     * Maps fields from a source entity to target data array based on mappings and direction.
     *
     * @param array<string, array<string, string>> $relationMaps Keyed by entity type,
     *   each containing a map of source values to resolved target values.
     *   Example: ['account' => ['crm-acc-1' => 'acme', 'crm-acc-2' => 'globex']]
     * @return array<string, mixed>
     */
    public function map(
        EntityInterface $entity,
        MappingCollection $collection,
        SyncDirection $direction,
        array $relationMaps = [],
    ): array {
        $result = [];

        // multi_value strategies deferred until after all append values are collected
        /** @var array<string, MultiValueConfig> */
        $deferredMultiValue = [];

        foreach ($collection->mappings as $mapping) {
            // CrmToCc: read from CRM field, write to CC field
            // CcToCrm: read from CC field, write to CRM field
            if ($direction === SyncDirection::CrmToCc) {
                $readField = $mapping->crmField;
                $writeField = $mapping->ccField;
            } else {
                $readField = $mapping->ccField;
                $writeField = $mapping->crmField;
            }

            // A rule with nowhere to write cannot be honoured, and guessing is
            // worse than refusing: writing under the empty key puts a "" entry in
            // the payload the CRM either stores as junk or rejects with a 400, and
            // the rule silently fails to override the base rule it was meant to
            // replace (forType() keys on crm_field, so it never matches). Reached
            // by a static-value rule that omits crm_field on a cc_to_crm mapping —
            // legal on import, where crm_field is the read side, but on export it
            // IS the target.
            if ($writeField === '') {
                throw MappingException::missingTargetField($mapping->ccField, $direction->value);
            }

            if ($mapping->hasStaticValue) {
                $value = $mapping->staticValue;
            } else {
                $value = $this->readNestedValue($entity, $readField);
            }
            $value = $this->applyTransformers($value, $mapping->transformers);

            // Apply relation resolution if configured. Accept int FKs too:
            // numeric-id CRMs (Pipedrive, HubSpot, …) hand back integer foreign
            // keys, and the old is_string() guard silently skipped resolution for
            // them, writing the raw CRM id instead of the resolved Daktela name.
            if ($mapping->relation !== null && (is_string($value) || is_int($value)) && $value !== '') {
                $value = $this->resolveRelation($value, $mapping->relation, $relationMaps);
            }

            if ($mapping->append) {
                // For append fields, defer multi_value to post-processing so it
                // runs on the final accumulated array, not on each individual value.
                if ($mapping->multiValue !== null) {
                    $deferredMultiValue[$writeField] = $mapping->multiValue;
                }
                $this->appendNestedValue($result, $writeField, $value);
            } else {
                // Apply multi-value strategy if configured
                if ($mapping->multiValue !== null) {
                    $value = $this->applyMultiValue($mapping->multiValue, $value);
                }
                $this->setNestedValue($result, $writeField, $value);
            }
        }

        // Collapse accumulated append fields (e.g. join ["John", "Doe"] → "John Doe")
        foreach ($deferredMultiValue as $field => $multiValue) {
            $accumulated = $this->getNestedValue($result, $field);
            if ($accumulated !== null) {
                $this->setNestedValue($result, $field, $this->applyMultiValue($multiValue, $accumulated));
            }
        }

        return $result;
    }

    public function readNestedValue(EntityInterface $entity, string $field): mixed
    {
        if (!str_contains($field, '.')) {
            return $entity->get($field);
        }

        $parts = explode('.', $field);
        $value = $entity->get($parts[0]);

        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            if (!is_array($value) || !array_key_exists($parts[$i], $value)) {
                return null;
            }
            $value = $value[$parts[$i]];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function setNestedValue(array &$result, string $field, mixed $value): void
    {
        if (!str_contains($field, '.')) {
            $result[$field] = $value;
            return;
        }

        $parts = explode('.', $field);
        $current = &$result;

        for ($i = 0, $last = count($parts) - 1; $i < $last; $i++) {
            if (!isset($current[$parts[$i]]) || !is_array($current[$parts[$i]])) {
                $current[$parts[$i]] = [];
            }
            $current = &$current[$parts[$i]];
        }

        $current[$parts[array_key_last($parts)]] = $value;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function appendNestedValue(array &$result, string $field, mixed $value): void
    {
        $existing = $this->getNestedValue($result, $field);

        $existingArray = is_array($existing) ? $existing : ($existing !== null ? [$existing] : []);
        $newArray = is_array($value) ? $value : ($value !== null && $value !== '' ? [$value] : []);

        $this->setNestedValue($result, $field, array_merge($existingArray, $newArray));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getNestedValue(array $data, string $field): mixed
    {
        return NestedValue::get($data, $field);
    }

    /**
     * @param array<array{name: string, params: array<string, mixed>}> $transformers
     */
    private function applyTransformers(mixed $value, array $transformers): mixed
    {
        foreach ($transformers as $config) {
            $transformer = $this->registry->get($config['name']);
            $value = $transformer->transform($value, $config['params']);
        }

        return $value;
    }

    /**
     * Run a multi_value strategy, then its own transformers on the result.
     *
     * The order is the point. Per-rule transformers run before accumulation, so
     * they only ever see one field's value; these run after the collapse, which
     * is the only moment the combination exists. Applied on both the append and
     * the non-append path so a multi_value block means the same thing either way.
     */
    private function applyMultiValue(MultiValueConfig $multiValue, mixed $value): mixed
    {
        return $this->applyTransformers($multiValue->apply($value), $multiValue->transformers);
    }

    /**
     * Translate a CRM foreign key into its Daktela counterpart, passing the value
     * through unchanged when the map has no entry for it (a documented
     * pass-through: the referenced record simply is not synced).
     *
     * The ORIGINAL value is returned on a miss, not a stringified copy of it.
     * Numeric-id CRMs hand back integer keys, and turning an unresolved 4712 into
     * "4712" is rejected by strictly-typed number fields on the way back out.
     *
     * @param array<string, array<string, string>> $relationMaps
     */
    private function resolveRelation(
        string|int $value,
        RelationConfig $relation,
        array $relationMaps,
    ): string|int {
        $map = $relationMaps[$relation->entity] ?? [];

        return $map[(string) $value] ?? $value;
    }
}
