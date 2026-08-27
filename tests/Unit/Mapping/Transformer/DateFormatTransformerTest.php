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

    public function testCanonicalMidnightIsConverted(): void
    {
        // A real midnight is an instant like any other, and matching `from` is all
        // it takes. Worth pinning because "does this value carry a time?" cannot be
        // answered from the value: date_parse() reports hour/minute/second all zero
        // for both "today" and an explicit midnight, so any rule reading the value
        // has to get one of them wrong. This one reads the format instead.
        $params = [
            'from' => 'Y-m-d H:i:s',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ];

        self::assertSame('2024-05-31', $this->transformer->transform('2024-06-01 00:00:00', $params + ['to' => 'Y-m-d']));
        self::assertSame('22:00', $this->transformer->transform('2024-06-01 00:00:00', $params + ['to' => 'H:i']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('declaredFormatProvider')]
    public function testAnyFormatConvertsOnceItIsDeclared(string $from, string $value, string $expected): void
    {
        // The contract: declare what the source emits and it converts. No notation
        // is privileged and nothing is inferred from the value — an offset-bearing
        // ISO timestamp, a unix epoch and a dotted European datetime all work the
        // same way, by saying so in `from`.
        //
        // A value carrying its own offset overrides from_tz, which is why from_tz is
        // deliberately wrong for those cases: 12:30 UTC is 14:30 in Prague.
        $result = $this->transformer->transform($value, [
            'from' => $from,
            'to' => 'Y-m-d H:i',
            'from_tz' => 'America/New_York',
            'to_tz' => 'Europe/Prague',
        ]);

        self::assertSame($expected, $result, sprintf('"%s" declared as "%s" must convert', $value, $from));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function declaredFormatProvider(): iterable
    {
        yield 'iso with numeric offset' => ['Y-m-d\TH:i:sP', '2024-06-01T12:30:00+00:00', '2024-06-01 14:30'];
        // One declared format covers both offset notations: P parses "Z" as well.
        yield 'iso with Z' => ['Y-m-d\TH:i:sP', '2024-06-01T12:30:00Z', '2024-06-01 14:30'];
        // Escaping it (\Z) declares a literal instead, so no zone is parsed and the
        // value is read in from_tz. The format means exactly what it says.
        yield 'escaped Z is a literal' => ['Y-m-d\TH:i:s\Z', '2024-06-01T12:30:00Z', '2024-06-01 18:30'];
        yield 'unix epoch' => ['U', '1717245000', '2024-06-01 14:30'];
        yield 'dotted european' => ['d.m.Y H:i:s', '01.06.2024 12:30:00', '2024-06-01 18:30'];
        yield 'offset-bearing midnight' => ['Y-m-d\TH:i:sP', '2024-06-01T00:00:00+02:00', '2024-06-01 00:00'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonMatchingValueProvider')]
    public function testValuesNotMatchingTheDeclaredFormatAreNeverShifted(string $value, string $expected): void
    {
        // A value that did not match `from` is of unknown shape, and no rule reading
        // it can decide whether it is an instant. It is still parsed generically and
        // reformatted (legacy behaviour), but never zone-shifted. If real timestamps
        // land here, `from` does not describe the data — see
        // testAnyFormatConvertsOnceItIsDeclared for the fix.
        $result = $this->transformer->transform($value, [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d H:i',
            'from_tz' => 'Europe/Prague',
            'to_tz' => 'UTC',
        ]);

        self::assertSame($expected, $result, sprintf('value "%s" did not match `from` and must not shift', $value));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonMatchingValueProvider(): iterable
    {
        yield 'iso with T separator' => ['2024-06-01T14:30:00', '2024-06-01 14:30'];
        yield 'iso basic' => ['20240601T143000', '2024-06-01 14:30'];
        yield 'rfc 2822 datetime' => ['Sat, 01 Jun 2024 14:30:00', '2024-06-01 14:30'];
        yield 'rfc 2822 date only' => ['Sat, 01 Jun 2024', '2024-06-01 00:00'];
        yield 'plain iso date' => ['2024-06-01', '2024-06-01 00:00'];
        yield 'textual date' => ['1 Jun 2024', '2024-06-01 00:00'];
        yield 'meridiem time' => ['2024-06-01 2pm', '2024-06-01 14:00'];
        yield 'dotted time' => ['01-Jun-2024 14.30', '2024-06-01 14:30'];
    }

    public function testUnparseableValuePassesThrough(): void
    {
        self::assertSame('not a date', $this->transformer->transform('not a date', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d',
            'to_tz' => 'UTC',
        ]));
    }

    /**
     * Regression: a bad zone must fail an EMPTY value too.
     *
     * While the zones were built after the empty-value early return, a typo'd
     * zone failed only the records carrying a date. That made the batch mixed,
     * and a mixed batch advances the watermark — permanently skipping exactly
     * the records it dropped. Failing uniformly is what lets saveState() withhold
     * the watermark.
     */
    public function testAnUnknownTimezoneFailsEvenForAnEmptyValue(): void
    {
        $t = new DateFormatTransformer();

        // \Exception, not \DateInvalidTimeZoneException: that class is 8.3+, and
        // composer.json declares >=8.2 with 8.2 in the CI matrix. On 8.2 the
        // constructor throws a plain \Exception.
        $this->expectException(\Exception::class);
        $t->transform('', ['from' => 'Y-m-d', 'to' => 'c', 'to_tz' => 'Europe/Praha']);
    }

    public function testAnUnknownTimezoneAlsoFailsForAPopulatedValue(): void
    {
        $t = new DateFormatTransformer();

        // \Exception, not \DateInvalidTimeZoneException: that class is 8.3+, and
        // composer.json declares >=8.2 with 8.2 in the CI matrix. On 8.2 the
        // constructor throws a plain \Exception.
        $this->expectException(\Exception::class);
        $t->transform('2026-08-27', ['from' => 'Y-m-d', 'to' => 'c', 'to_tz' => 'Europe/Praha']);
    }

    /**
     * With no `from_tz`, a naive datetime is read in the PROCESS timezone.
     *
     * The Daktela v6 API returns naive local datetimes, so the correctness of
     * every unconverted timestamp on a non-UTC instance rests on that default.
     * Hardcoding UTC instead left the suite green — invisible under TZ=UTC, and
     * an hour or two wrong everywhere else.
     */
    public function testAnAbsentFromTimezoneMeansTheProcessTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');

        try {
            $out = (new DateFormatTransformer())->transform('2026-01-15 12:00:00', [
                'from' => 'Y-m-d H:i:s',
                'to' => 'c',
                'to_tz' => 'UTC',
            ]);

            // 12:00 Prague in January (UTC+1) is 11:00 UTC. Under a hardcoded
            // UTC default it would come back as 12:00.
            self::assertSame('2026-01-15T11:00:00+00:00', $out);
        } finally {
            date_default_timezone_set($original);
        }
    }
}
