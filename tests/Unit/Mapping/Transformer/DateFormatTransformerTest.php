<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping\Transformer;

use Daktela\CrmSync\Mapping\Transformer\DateFormatTransformer;
use PHPUnit\Framework\TestCase;

final class DateFormatTransformerTest extends TestCase
{
    private DateFormatTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new DateFormatTransformer();
    }

    public function testGetName(): void
    {
        self::assertSame('date_format', $this->transformer->getName());
    }

    public function testTransformWithExplicitFormats(): void
    {
        $result = $this->transformer->transform('2025-01-15 14:30:00', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d',
        ]);

        self::assertSame('2025-01-15', $result);
    }

    public function testTransformToIsoFormat(): void
    {
        $result = $this->transformer->transform('2025-06-01 10:00:00', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'c',
        ]);

        self::assertStringContainsString('2025-06-01', (string) $result);
    }

    public function testNullReturnsNull(): void
    {
        self::assertNull($this->transformer->transform(null));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->transformer->transform(''));
    }

    public function testFallbackToGenericParsing(): void
    {
        $result = $this->transformer->transform('January 15, 2025', [
            'from' => 'Y/m/d',
            'to' => 'Y-m-d',
        ]);

        self::assertSame('2025-01-15', $result);
    }

    public function testToTzConvertsSummerTimeWithDstOffset(): void
    {
        // Prague in June is UTC+2 (DST) — 14:30 local is 12:30 UTC.
        $result = $this->transformer->transform('2026-06-01 14:30:00', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'H:i',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame('12:30', $result);
    }

    public function testToTzConvertsWinterTimeWithDstOffset(): void
    {
        // Prague in January is UTC+1 — the same wall time converts differently.
        $result = $this->transformer->transform('2026-01-15 14:30:00', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'H:i',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame('13:30', $result);
    }

    public function testToTzRollsDateBackwardAtMidnight(): void
    {
        // 00:30 Prague summer time is 22:30 UTC the previous day — date and time
        // must shift together as one instant.
        $params = [
            'from' => 'Y-m-d H:i:s',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ];

        self::assertSame('2026-05-31', $this->transformer->transform('2026-06-01 00:30:00', $params + ['to' => 'Y-m-d']));
        self::assertSame('22:30', $this->transformer->transform('2026-06-01 00:30:00', $params + ['to' => 'H:i']));
    }

    public function testWithoutTzParamsBehaviourIsUnchanged(): void
    {
        // Legacy configs without from_tz/to_tz must reformat naively, no shifting.
        $result = $this->transformer->transform('2026-06-01 14:30:00', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'H:i',
        ]);

        self::assertSame('14:30', $result);
    }

    public function testDateOnlyInputDoesNotInheritTimeFromNow(): void
    {
        // createFromFormat fills unspecified fields from "now" unless anchored;
        // the transformer must anchor them to zero or a date-only value combined
        // with to_tz would shift by a whole day depending on when the sync runs.
        $result = $this->transformer->transform('2026-08-19', [
            'from' => 'Y-m-d',
            'to' => 'Y-m-d H:i:s',
        ]);

        self::assertSame('2026-08-19 00:00:00', $result);
    }

    public function testDateOnlyFormatIgnoresToTz(): void
    {
        // A date is not an instant: shifting "2026-08-19" into UTC would emit
        // 2026-08-18 on any instance east of UTC (midnight Prague = 22:00 UTC
        // the previous day) while silently "working" west of it. When the from
        // format carries no time-of-day, to_tz must be a no-op.
        $result = $this->transformer->transform('2026-08-19', [
            'from' => 'Y-m-d',
            'to' => 'Y-m-d H:i',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame('2026-08-19 00:00', $result);
    }

    public function testEscapedFormatCharsDoNotCountAsTime(): void
    {
        // \H is a literal "H" in the value, not an hour specifier — the format
        // is still date-only, so to_tz must stay a no-op.
        $result = $this->transformer->transform('2026-08-19H', [
            'from' => 'Y-m-d\\H',
            'to' => 'Y-m-d',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame('2026-08-19', $result);
    }

    public function testCallerSuppliedAnchorIsNotDoubled(): void
    {
        // A format that already anchors (leading !) must pass through untouched.
        $result = $this->transformer->transform('2026-08-19', [
            'from' => '!Y-m-d',
            'to' => 'Y-m-d H:i:s',
        ]);

        self::assertSame('2026-08-19 00:00:00', $result);
    }

    public function testDateOnlyValueViaFallbackParseIgnoresToTz(): void
    {
        // Mixed-format source data: the configured format carries time, but this
        // value doesn't and reaches the generic fallback parser. The date-only
        // rule must apply to the value too, or it shifts by a whole day.
        $result = $this->transformer->transform('2024-06-01', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame('2024-06-01', $result);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rfc2822DateTimeProvider')]
    public function testRfc2822DateTimesAreStillConverted(string $value, string $expected): void
    {
        // A weekday name does not make a value date-only: RFC 2822 / HTTP dates are
        // the canonical email Date: header shape and carry a real time, which must
        // still be shifted into the target zone.
        $result = $this->transformer->transform($value, [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d H:i',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame($expected, $result, sprintf('value "%s" carries a time and must convert', $value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function rfc2822DateTimeProvider(): iterable
    {
        // 14:30 Prague (CEST, +2) = 12:30 UTC
        yield 'rfc 2822 without offset' => ['Sat, 01 Jun 2024 14:30:00', '2024-06-01 12:30'];
        // an explicit offset in the value wins over from_tz: 14:30+02:00 = 12:30 UTC
        yield 'rfc 2822 with offset' => ['Sat, 01 Jun 2024 14:30:00 +0200', '2024-06-01 12:30'];
        yield 'relative keyword with a time' => ['2024-06-01 09:00:00 GMT', '2024-06-01 09:00'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('timeCarryingFallbackValuesProvider')]
    public function testFallbackParseStillConvertsWhenValueCarriesTime(string $value): void
    {
        // The date-only rule must key on what was actually parsed, not on a colon
        // pattern: compact ISO 8601 (iCalendar DTSTART), "2pm" and dotted times
        // all carry a time and must be converted.
        $result = $this->transformer->transform($value, [
            'from' => 'Y-m-d H:i:s',
            'to' => 'H:i',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame('12:30', $result, sprintf('value "%s" carries a time and must convert', $value));
    }

    /** @return iterable<string, array{string}> */
    public static function timeCarryingFallbackValuesProvider(): iterable
    {
        yield 'iso extended' => ['2024-06-01T14:30:00'];
        yield 'iso basic' => ['20240601T143000'];
        yield 'iso basic without separator' => ['20240601143000'];
        yield 'dotted time' => ['01-Jun-2024 14.30'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dateOnlyFallbackValuesProvider')]
    public function testFallbackParseLeavesDateOnlyValuesUnshifted(string $value, string $expected): void
    {
        // date_parse sets hour = 0 as a side effect of a relative token, so these
        // carry no time and must not be converted — an RFC-2822 date ("Sat, 01 Jun
        // 2024") is routine in email/CRM exports.
        $result = $this->transformer->transform($value, [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame($expected, $result, sprintf('value "%s" is date-only and must not shift', $value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function dateOnlyFallbackValuesProvider(): iterable
    {
        yield 'rfc 2822 date-only' => ['Sat, 01 Jun 2024', '2024-06-01'];
        yield 'plain iso date' => ['2024-06-01', '2024-06-01'];
        yield 'textual date' => ['1 Jun 2024', '2024-06-01'];
        yield 'bare relative keyword' => ['today', (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Prague')))->format('Y-m-d')];
    }

    public function testFallbackParseConvertsMeridiemValues(): void
    {
        $result = $this->transformer->transform('2024-06-01 2pm', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'H:i',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame('12:00', $result);
    }
}
