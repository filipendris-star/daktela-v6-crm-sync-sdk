<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

final readonly class MappingCollection
{
    /**
     * @param FieldMapping[] $mappings base rules (the `default:` section, or legacy top-level `mappings:`)
     * @param array<string, FieldMapping[]> $typeMappings per-activity-type rules keyed by type value (e.g. "call", "sms")
     */
    public function __construct(
        public string $entityType,
        public string $lookupField,
        public array $mappings,
        public array $typeMappings = [],
    ) {
    }

    /**
     * Resolve the effective rule set for one activity type: base rules with the
     * type's rules merged over them. A non-append type rule replaces a non-append
     * base rule that targets the same output field (crm_field; cc_field for
     * static-value rules without one), otherwise it is appended. Append rules are
     * never deduped — `append: true` exists precisely so several rules accumulate
     * into one field, so both base and type append rules always survive the merge.
     * Unknown/null type returns the base rules unchanged.
     */
    public function forType(?string $type): self
    {
        $typeRules = $type !== null ? ($this->typeMappings[$type] ?? []) : [];
        if ($typeRules === []) {
            return new self($this->entityType, $this->lookupField, $this->mappings);
        }

        // Index non-append type rules by target so they can replace their base
        // counterpart in place (preserving base rule order).
        $typeByKey = [];
        foreach ($typeRules as $i => $rule) {
            if (!$rule->append) {
                $typeByKey[$this->mergeKey($rule)] = $i;
            }
        }

        $merged = [];
        $usedTypeIdx = [];
        foreach ($this->mappings as $rule) {
            $key = $this->mergeKey($rule);
            if (!$rule->append && isset($typeByKey[$key])) {
                $idx = $typeByKey[$key];
                $merged[] = $typeRules[$idx];
                $usedTypeIdx[$idx] = true;
            } else {
                $merged[] = $rule;
            }
        }
        foreach ($typeRules as $i => $rule) {
            if (!isset($usedTypeIdx[$i])) {
                $merged[] = $rule;
            }
        }

        return new self($this->entityType, $this->lookupField, $merged);
    }

    private function mergeKey(FieldMapping $rule): string
    {
        return $rule->crmField !== '' ? 'crm:' . $rule->crmField : 'cc:' . $rule->ccField;
    }
}
