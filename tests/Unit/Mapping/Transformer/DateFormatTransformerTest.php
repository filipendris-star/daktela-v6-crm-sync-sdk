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
}
