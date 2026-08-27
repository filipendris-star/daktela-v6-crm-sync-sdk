<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class DateFormatTransformer implements ValueTransformerInterface
{
    /** Daktela's datetime format — the default, and what the v6 API always emits. */
    private const DEFAULT_FROM_FORMAT = 'Y-m-d H:i:s';

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
     * `from` is the contract, and to_tz applies on exactly two conditions, both
     * read off it: the format parses a time of day, and the value actually matched
     * it. Nothing about the value itself is inspected or inferred.
     *
     * That is deliberate. Zone conversion needs an unambiguous instant, and asking
     * a value of unknown shape whether it is one is not answerable — date_parse()
     * reports hour=0/minute=0/second=0 for both "today" and a real midnight. Every
     * attempt to decide it from the data got some class of value wrong: naive
     * midnights, epochs, compact ISO, weekday-prefixed dates, offset-bearing ISO.
     * The format already carries the answer, so it is simply declared instead.
     *
     * The practical rule for configs: **declare the format your source emits.**
     * ISO-8601 with an offset converts under `from: 'Y-m-d\TH:i:sP'`; a unix
     * timestamp under `from: 'U'`; a dotted European datetime under
     * `from: 'd.m.Y H:i:s'`. A value that does not match `from` is still parsed
     * generically and reformatted (legacy behaviour) but never zone-shifted — if
     * timestamps come out unconverted, `from` does not describe the data.
     *
     * A date-only format never converts, whatever the value holds: a date is not an
     * instant, and shifting "2026-08-19" would move it a day backward east of UTC
     * while silently "working" west of it.
     *
     * @param array<string, mixed> $params
     */
    public function transform(mixed $value, array $params = []): mixed
    {
        // Zones are built BEFORE the empty-value early return, deliberately. A
        // bad zone name throws, and building it after the return would fail only
        // the records carrying a date while empty ones succeeded — a partial
        // batch failure, which advances the watermark past the dropped records.
        // Built first, a broken zone fails every record that shares this RULE
        // SET, rather than only the ones carrying a date — so an all-failed step
        // makes saveState() withhold the watermark instead of advancing past the
        // records it dropped.
        //
        // It is NOT a batch-wide guarantee: put the bad zone in a per-type
        // (`types:`) rule and only that type's records fail, which is a mixed
        // batch and the watermark still advances. The load-time check in
        // YamlMappingLoader is what actually covers that shape, and it covers
        // every rule path — so the gap is programmatically-built mappings only.
        $fromTz = new \DateTimeZone(isset($params['from_tz'])
            ? (string) $params['from_tz']
            : date_default_timezone_get());
        $toTz = isset($params['to_tz']) ? new \DateTimeZone((string) $params['to_tz']) : null;

        if ($value === null || $value === '') {
            return $value;
        }

        $from = (string) ($params['from'] ?? self::DEFAULT_FROM_FORMAT);
        $to = (string) ($params['to'] ?? 'Y-m-d');

        // Anchor unspecified fields to zero (via `|`) instead of letting
        // createFromFormat fill them from "now": with to_tz set, a date-only
        // input would otherwise shift by a whole day depending on the time of
        // day the sync happens to run.
        $anchoredFrom = str_contains($from, '!') || str_contains($from, '|') ? $from : $from . '|';

        $date = \DateTimeImmutable::createFromFormat($anchoredFrom, (string) $value, $fromTz);
        $matchedFormat = $date !== false;

        if ($date === false) {
            // Try parsing as any recognizable format
            try {
                $date = new \DateTimeImmutable((string) $value, $fromTz);
            } catch (\Exception) {
                return $value;
            }
        }

        // Convert only what `from` vouches for: it must parse a time of day, and
        // the value must have actually matched it. A value that fell through to the
        // generic parser is of unknown shape, and no rule reading it can decide
        // whether it is an instant — so it is reformatted, never shifted.
        if ($toTz !== null && !($matchedFormat && $this->formatCarriesTime($from))) {
            $toTz = null;
        }

        if ($toTz !== null) {
            $date = $date->setTimezone($toTz);
        }

        return $date->format($to);
    }

    /**
     * True when the format parses a time of day (hour/minute/second/fraction or a
     * unix timestamp). Backslash-escaped characters are literals, not specifiers,
     * and don't count.
     */
    private function formatCarriesTime(string $format): bool
    {
        $specifiers = preg_replace('/\\\\./', '', $format) ?? $format;

        return preg_match('/[HGhgisvuU]/', $specifiers) === 1;
    }
}
