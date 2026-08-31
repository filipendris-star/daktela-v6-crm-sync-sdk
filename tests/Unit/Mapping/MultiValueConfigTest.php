<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping;

use Daktela\CrmSync\Mapping\MultiValueConfig;
use Daktela\CrmSync\Mapping\MultiValueStrategy;
use PHPUnit\Framework\TestCase;

final class MultiValueConfigTest extends TestCase
{
    // --- AsArray ---

    public function testAsArrayKeepsArrayValue(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::AsArray);

        self::assertSame(['a', 'b'], $config->apply(['a', 'b']));
    }

    public function testAsArrayWrapsScalar(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::AsArray);

        self::assertSame(['hello'], $config->apply('hello'));
    }

    public function testAsArrayReturnsEmptyForNull(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::AsArray);

        self::assertSame([], $config->apply(null));
    }

    public function testAsArrayReturnsEmptyForEmptyString(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::AsArray);

        self::assertSame([], $config->apply(''));
    }

    // --- Join ---

    public function testJoinArrayWithComma(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Join, ', ');

        self::assertSame('sports, music, art', $config->apply(['sports', 'music', 'art']));
    }

    public function testJoinScalarReturnsString(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Join, ', ');

        self::assertSame('hello', $config->apply('hello'));
    }

    /**
     * A joined boolean has to survive as a value, not vanish into an empty slot.
     *
     * implode() casts false to "" and true to "1", so `false` came out
     * indistinguishable from an empty string or a dropped element — and the
     * whole point of joining several fields is that the result identifies the
     * combination. Rendered explicitly, `false` is "0".
     */
    public function testJoinRendersBooleansAsZeroAndOne(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Join, '_');

        self::assertSame('out_0', $config->apply(['out', false]));
        self::assertSame('out_1', $config->apply(['out', true]));
    }

    public function testJoinDistinguishesFalseFromEmptyString(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Join, '_');

        self::assertNotSame($config->apply(['x', '']), $config->apply(['x', false]));
    }

    public function testJoinNullReturnsEmpty(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Join);

        self::assertSame('', $config->apply(null));
    }

    public function testJoinEmptyArrayReturnsEmpty(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Join, ',');

        self::assertSame('', $config->apply([]));
    }

    // --- Split ---

    public function testSplitStringByComma(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Split, ',');

        self::assertSame(['web', 'mobile', 'api'], $config->apply('web,mobile,api'));
    }

    public function testSplitTrimsWhitespace(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Split, ',');

        self::assertSame(['web', 'mobile', 'api'], $config->apply('web, mobile, api'));
    }

    public function testSplitKeepsArrayAsIs(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Split, ',');

        self::assertSame(['a', 'b'], $config->apply(['a', 'b']));
    }

    public function testSplitNullReturnsEmpty(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Split, ',');

        self::assertSame([], $config->apply(null));
    }

    public function testSplitEmptyStringReturnsEmpty(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Split, ',');

        self::assertSame([], $config->apply(''));
    }

    public function testSplitWithPipeSeparator(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Split, '|');

        self::assertSame(['a', 'b', 'c'], $config->apply('a|b|c'));
    }

    // --- First ---

    public function testFirstReturnsFirstElement(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::First);

        self::assertSame('alpha', $config->apply(['alpha', 'beta', 'gamma']));
    }

    public function testFirstReturnsNullForEmptyArray(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::First);

        self::assertNull($config->apply([]));
    }

    public function testFirstReturnsScalarAsIs(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::First);

        self::assertSame('hello', $config->apply('hello'));
    }

    // --- Last ---

    public function testLastReturnsLastElement(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Last);

        self::assertSame('gamma', $config->apply(['alpha', 'beta', 'gamma']));
    }

    public function testLastReturnsNullForEmptyArray(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Last);

        self::assertNull($config->apply([]));
    }

    public function testLastReturnsScalarAsIs(): void
    {
        $config = new MultiValueConfig(MultiValueStrategy::Last);

        self::assertSame('hello', $config->apply('hello'));
    }
}
