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
     * type's rules merged over them. A type rule replaces a base rule that targets
     * the same output field (crm_field; cc_field for static-value rules without one),
     * otherwise it is appended. Unknown/null type returns the base rules unchanged.
     */
    public function forType(?string $type): self
    {
        $typeRules = $type !== null ? ($this->typeMappings[$type] ?? []) : [];
        if ($typeRules === []) {
            return new self($this->entityType, $this->lookupField, $this->mappings);
        }

        $merged = [];
        foreach ($this->mappings as $rule) {
            $merged[$this->mergeKey($rule)] = $rule;
        }
        foreach ($typeRules as $rule) {
            $merged[$this->mergeKey($rule)] = $rule;
        }

        return new self($this->entityType, $this->lookupField, array_values($merged));
    }

    private function mergeKey(FieldMapping $rule): string
    {
        return $rule->crmField !== '' ? 'crm:' . $rule->crmField : 'cc:' . $rule->ccField;
    }
}
