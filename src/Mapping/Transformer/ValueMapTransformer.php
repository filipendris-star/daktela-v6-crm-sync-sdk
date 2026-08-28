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
 * Example — translate the activity direction into a CRM's own vocabulary:
 *   transformers:
 *     - name: value_map
 *       params: { map: { in: inbound, out: outbound }, default: internal }
 *
 * Mind the SOURCE TYPE when writing keys. Daktela's boolean-ish fields are
 * integers (1/0/NULL, serialised by the v6 API as "1"/"0"), so a map keyed
 * `"false"` matches only a real PHP false and every record silently takes the
 * default; key those `"0"` / `"1"`. Direction casing also varies by activity type
 * — call and email items store 'in'/'out', the chat family stores 'IN'/'OUT' — so
 * a map that lists only one case sends the rest to the default.
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
        if (!is_array($map)) {
            $map = [];
        }

        // An empty map with a `default` still means "everything becomes the
        // default" — returning the input unchanged silently ignored the rule.
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
