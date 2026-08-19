<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class DateFormatTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'date_format';
    }

    /**
     * params:
     *   from:    input format for createFromFormat (default 'Y-m-d H:i:s')
     *   to:      output format (default 'Y-m-d')
     *   from_tz: timezone the input wall-time is expressed in. Defaults to the
     *            process/instance timezone (date_default_timezone_get()), because
     *            the Daktela v6 API returns naive local datetimes.
     *   to_tz:   timezone to convert to before formatting. When set, the instant
     *            is shifted into this zone first — this is what makes a naive
     *            local time land correctly in a CRM that interprets times as UTC
     *            (e.g. Pipedrive due_time). Omit for the legacy naive behaviour.
     *
     * Using named zones (not fixed offsets) means DST is handled per-date, so a
     * summer time converts at +2h and a winter time at +1h automatically.
     *
     * to_tz only applies when the `from` format carries time-of-day. A date-only
     * value is not an instant — shifting it across zones would move the date a
     * whole day backward east of UTC while silently "working" west of it — so for
     * date-only formats the conversion is skipped and the date passes through.
     *
     * @param array<string, mixed> $params
     */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $from = (string) ($params['from'] ?? 'Y-m-d H:i:s');
        $to = (string) ($params['to'] ?? 'Y-m-d');

        $fromTz = new \DateTimeZone(isset($params['from_tz'])
            ? (string) $params['from_tz']
            : date_default_timezone_get());
        $toTz = isset($params['to_tz']) ? new \DateTimeZone((string) $params['to_tz']) : null;

        if ($toTz !== null && !$this->formatCarriesTime($from)) {
            $toTz = null;
        }

        // Anchor unspecified fields to zero (via `|`) instead of letting
        // createFromFormat fill them from "now": with to_tz set, a date-only
        // input would otherwise shift by a whole day depending on the time of
        // day the sync happens to run.
        $anchoredFrom = str_contains($from, '!') || str_contains($from, '|') ? $from : $from . '|';

        $date = \DateTimeImmutable::createFromFormat($anchoredFrom, (string) $value, $fromTz);
        if ($date === false) {
            // Try parsing as any recognizable format
            try {
                $date = new \DateTimeImmutable((string) $value, $fromTz);
            } catch (\Exception) {
                return $value;
            }

            // The date-only rule above keys on the configured format, but this
            // path accepted a value the format did not describe: re-apply the
            // rule to the value itself, or a date-only value slipping through a
            // datetime format would still shift by a whole day.
            //
            // date_parse reports what it actually parsed, so compact ISO
            // ("20240601T143000"), "2pm" and "14.30" are recognised as carrying a
            // time — but it also sets hour = 0 as a side effect of a relative
            // token, which is why "Sat, 01 Jun 2024" (RFC 2822 date-only) and
            // "yesterday" must be excluded via the `relative` key. Known limits:
            // an explicit "midnight" and the bare keyword "today" are treated as
            // instants.
            if ($toTz !== null && !$this->valueCarriesTime((string) $value)) {
                $toTz = null;
            }
        }

        if ($toTz !== null) {
            $date = $date->setTimezone($toTz);
        }

        return $date->format($to);
    }

    /**
     * True when the VALUE itself parsed a time of day. `hour` alone is not enough:
     * a relative token (weekday name, "yesterday", "next monday") sets it to 0
     * without any time being present.
     */
    private function valueCarriesTime(string $value): bool
    {
        $parsed = date_parse($value);

        return ($parsed['hour'] ?? false) !== false && empty($parsed['relative']);
    }

    /**
     * True when the format parses a time of day (hour/minute/second/fraction or
     * a unix timestamp). Backslash-escaped characters are literals, not
     * specifiers, and don't count.
     */
    private function formatCarriesTime(string $format): bool
    {
        $specifiers = preg_replace('/\\\\./', '', $format) ?? $format;

        return preg_match('/[HGhgisvuU]/', $specifiers) === 1;
    }
}
