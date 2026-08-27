<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping\Transformer;

use Daktela\CrmSync\Mapping\Transformer\ValueMapTransformer;
use PHPUnit\Framework\TestCase;

final class ValueMapTransformerTest extends TestCase
{
    private ValueMapTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new ValueMapTransformer();
    }

    public function testName(): void
    {
        self::assertSame('value_map', $this->transformer->getName());
    }

    public function testMapsStringValues(): void
    {
        $params = ['map' => ['in' => 0, 'out' => 1]];

        self::assertSame(0, $this->transformer->transform('in', $params));
        self::assertSame(1, $this->transformer->transform('out', $params));
    }

    public function testMapsBooleanValuesViaStringKeys(): void
    {
        $params = ['map' => ['false' => 0], 'default' => 1];

        self::assertSame(0, $this->transformer->transform(false, $params));
        self::assertSame(1, $this->transformer->transform(true, $params));
    }

    public function testMapsIntegerValuesViaStringKeys(): void
    {
        $params = ['map' => ['0' => 'no', '1' => 'yes']];

        self::assertSame('no', $this->transformer->transform(0, $params));
        self::assertSame('yes', $this->transformer->transform(1, $params));
    }

    public function testNullMatchesNullKey(): void
    {
        $params = ['map' => ['null' => 'missing']];

        self::assertSame('missing', $this->transformer->transform(null, $params));
    }

    public function testUnmatchedValueUsesDefaultWhenProvided(): void
    {
        $params = ['map' => ['in' => 0], 'default' => 1];

        self::assertSame(1, $this->transformer->transform('internal', $params));
    }

    public function testUnmatchedValuePassesThroughWithoutDefault(): void
    {
        $params = ['map' => ['in' => 0]];

        self::assertSame('internal', $this->transformer->transform('internal', $params));
    }

    public function testDefaultMayBeNull(): void
    {
        $params = ['map' => ['in' => 0], 'default' => null];

        self::assertNull($this->transformer->transform('whatever', $params));
    }

    public function testEmptyOrMissingMapPassesThrough(): void
    {
        self::assertSame('x', $this->transformer->transform('x', []));
        self::assertSame('x', $this->transformer->transform('x', ['map' => []]));
    }

    public function testNonScalarValuePassesThroughOrUsesDefault(): void
    {
        $params = ['map' => ['in' => 0]];
        self::assertSame(['a'], $this->transformer->transform(['a'], $params));

        $paramsWithDefault = ['map' => ['in' => 0], 'default' => 'fallback'];
        self::assertSame('fallback', $this->transformer->transform(['a'], $paramsWithDefault));
    }
}
