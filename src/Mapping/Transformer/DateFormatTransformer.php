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

        $date = \DateTimeImmutable::createFromFormat($from, (string) $value, $fromTz);
        if ($date === false) {
            // Try parsing as any recognizable format
            try {
                $date = new \DateTimeImmutable((string) $value, $fromTz);
            } catch (\Exception) {
                return $value;
            }
        }

        if ($toTz !== null) {
            $date = $date->setTimezone($toTz);
        }

        return $date->format($to);
    }
}
