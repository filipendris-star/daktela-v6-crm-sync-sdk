<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

final readonly class MultiValueConfig
{
    /**
     * @param list<array{name: string, params: array<string, mixed>}> $transformers
     *        applied to the COLLAPSED value, after the strategy has run. Per-rule
     *        transformers cannot reach that point: they run before accumulation,
     *        so each field is transformed alone and the combination never exists
     *        to be mapped. Joining several fields and then mapping the pair is
     *        the reason to join them at all.
     */
    public function __construct(
        public MultiValueStrategy $strategy,
        public string $separator = ',',
        public array $transformers = [],
    ) {
    }

    public function apply(mixed $value): mixed
    {
        return match ($this->strategy) {
            MultiValueStrategy::AsArray => $this->toArray($value),
            MultiValueStrategy::Join => $this->join($value),
            MultiValueStrategy::Split => $this->split($value),
            MultiValueStrategy::First => $this->first($value),
            MultiValueStrategy::Last => $this->last($value),
        };
    }

    private function toArray(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        return [$value];
    }

    private function join(mixed $value): string
    {
        if (is_array($value)) {
            return implode($this->separator, array_map($this->renderScalar(...), $value));
        }

        return $this->renderScalar($value);
    }

    /**
     * strval() casts false to "" and true to "1", so a joined boolean came out
     * indistinguishable from an empty string — and from a slot that was dropped
     * for being empty. That is fatal when the joined result is meant to identify
     * a COMBINATION of fields, which is what joining several of them is for.
     * Rendered explicitly, false is "0".
     */
    private function renderScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) ($value ?? '');
    }

    /** @return array<int, string> */
    private function split(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        $separator = $this->separator !== '' ? $this->separator : ',';

        return array_map(trim(...), explode($separator, (string) $value));
    }

    private function first(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value === [] ? null : reset($value);
        }

        return $value;
    }

    private function last(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value === [] ? null : end($value);
        }

        return $value;
    }
}
