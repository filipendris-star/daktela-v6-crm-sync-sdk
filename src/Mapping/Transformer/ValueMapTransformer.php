<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

/**
 * Maps discrete input values to configured output values.
 *
 * params:
 *   map:     associative array of input => output (YAML keys are strings;
 *            booleans are matched as "true"/"false", null as "null")
 *   default: value to use when the input is not present in the map.
 *            When omitted, unmatched input passes through unchanged.
 *
 * Example — derive Pipedrive activity "done" from a missed-call flag:
 *   transformers:
 *     - name: value_map
 *       params: { map: { "false": 0 }, default: 1 }
 */
final class ValueMapTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'value_map';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        $map = $params['map'] ?? [];
        if (!is_array($map) || $map === []) {
            return $value;
        }

        $key = $this->normalizeKey($value);

        if ($key !== null && array_key_exists($key, $map)) {
            return $map[$key];
        }

        if (array_key_exists('default', $params)) {
            return $params['default'];
        }

        return $value;
    }

    private function normalizeKey(mixed $value): ?string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
