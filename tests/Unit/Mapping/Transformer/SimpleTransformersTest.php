<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping\Transformer;

use Daktela\CrmSync\Mapping\Transformer\CallbackTransformer;
use Daktela\CrmSync\Mapping\Transformer\DefaultValueTransformer;
use Daktela\CrmSync\Mapping\Transformer\PrefixTransformer;
use Daktela\CrmSync\Mapping\Transformer\StringCaseTransformer;
use Daktela\CrmSync\Mapping\Transformer\StripPrefixTransformer;
use Daktela\CrmSync\Mapping\Transformer\UrlTransformer;
use Daktela\CrmSync\Mapping\Transformer\WrapArrayTransformer;
use PHPUnit\Framework\TestCase;

/**
 * The transformers that ship in the default registry but had no tests.
 *
 * They are config surface: a mapping file names one by string and passes it
 * params, so their edge behaviour (null, empty string, non-string input, an
 * unknown option) is a contract customers write YAML against — and every one of
 * them silently passes the value through on input it does not handle, which is
 * exactly the shape that produces a wrong CRM field rather than an error.
 */
final class SimpleTransformersTest extends TestCase
{
    // ── default_value ────────────────────────────────────────────────────────

    public function testDefaultValueFillsOnlyNull(): void
    {
        $t = new DefaultValueTransformer();

        self::assertSame('fallback', $t->transform(null, ['value' => 'fallback']));
        self::assertSame('kept', $t->transform('kept', ['value' => 'fallback']));
    }

    public function testDefaultValueLeavesAnEmptyStringAlone(): void
    {
        // '' is a value the source actually holds; only a MISSING value defaults.
        // Worth pinning — treating '' as absent would silently overwrite a
        // deliberately cleared CRM field.
        self::assertSame('', (new DefaultValueTransformer())->transform('', ['value' => 'fallback']));
    }

    public function testDefaultValueWithNoParamYieldsNull(): void
    {
        self::assertNull((new DefaultValueTransformer())->transform(null));
    }

    // ── prefix / strip_prefix ────────────────────────────────────────────────

    public function testPrefixPrependsAndSkipsEmptyInput(): void
    {
        $t = new PrefixTransformer();

        self::assertSame('CRM-42', $t->transform('42', ['value' => 'CRM-']));
        self::assertSame('', $t->transform('', ['value' => 'CRM-']), 'nothing to prefix');
        self::assertNull($t->transform(null, ['value' => 'CRM-']));
    }

    public function testStripPrefixRemovesOnlyALeadingMatch(): void
    {
        $t = new StripPrefixTransformer();

        self::assertSame('42', $t->transform('CRM-42', ['value' => 'CRM-']));
        self::assertSame('X-CRM-42', $t->transform('X-CRM-42', ['value' => 'CRM-']), 'not a prefix, left alone');
    }

    public function testStripPrefixStrictNullsAValueThatDoesNotCarryThePrefix(): void
    {
        // strict is how a mapping says "this field is only meaningful when it
        // carries our prefix" — without it, a foreign id passes through and is
        // written to the CRM as if it were ours.
        $t = new StripPrefixTransformer();

        self::assertNull($t->transform('other-42', ['value' => 'CRM-', 'strict' => true]));
        self::assertSame('42', $t->transform('CRM-42', ['value' => 'CRM-', 'strict' => true]));
    }

    public function testStripPrefixPassesThroughNonStrings(): void
    {
        $t = new StripPrefixTransformer();

        self::assertNull($t->transform(null, ['value' => 'CRM-']));
        self::assertSame('', $t->transform('', ['value' => 'CRM-']));
        self::assertSame(42, $t->transform(42, ['value' => 'CRM-']));
    }

    // ── url ──────────────────────────────────────────────────────────────────

    public function testUrlSubstitutesTheValueIntoTheTemplate(): void
    {
        $t = new UrlTransformer();

        self::assertSame(
            'https://crm.example.com/deal/42',
            $t->transform('42', ['template' => 'https://crm.example.com/deal/{value}']),
        );
    }

    public function testUrlWithoutATemplateReturnsTheBareValue(): void
    {
        self::assertSame('42', (new UrlTransformer())->transform('42'));
    }

    public function testUrlLeavesEmptyInputAlone(): void
    {
        // A template applied to nothing would write a link to a record that does
        // not exist.
        $t = new UrlTransformer();

        self::assertSame('', $t->transform('', ['template' => 'https://x/{value}']));
        self::assertNull($t->transform(null, ['template' => 'https://x/{value}']));
    }

    // ── string_case ──────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string, string}> */
    public static function stringCaseProvider(): iterable
    {
        yield 'lower' => ['lower', 'Ada LOVELACE', 'ada lovelace'];
        yield 'upper' => ['upper', 'Ada Lovelace', 'ADA LOVELACE'];
        yield 'title' => ['title', 'ada lovelace', 'Ada Lovelace'];
        yield 'unknown case is a no-op' => ['sentence', 'Ada Lovelace', 'Ada Lovelace'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stringCaseProvider')]
    public function testStringCase(string $case, string $input, string $expected): void
    {
        self::assertSame($expected, (new StringCaseTransformer())->transform($input, ['case' => $case]));
    }

    public function testStringCaseDefaultsToLower(): void
    {
        self::assertSame('abc', (new StringCaseTransformer())->transform('ABC'));
    }

    public function testStringCaseHandlesMultibyteInput(): void
    {
        // mb_* on purpose: Czech and other diacritics are routine in this data,
        // and strtolower would mangle them byte-wise.
        $t = new StringCaseTransformer();

        self::assertSame('žluťoučký', $t->transform('ŽLUŤOUČKÝ', ['case' => 'lower']));
        self::assertSame('ŽLUŤOUČKÝ', $t->transform('žluťoučký', ['case' => 'upper']));
    }

    public function testStringCasePassesThroughNonStrings(): void
    {
        $t = new StringCaseTransformer();

        self::assertNull($t->transform(null));
        self::assertSame(42, $t->transform(42));
    }

    // ── wrap_array ───────────────────────────────────────────────────────────

    public function testWrapArrayWrapsScalarsAndPreservesArrays(): void
    {
        $t = new WrapArrayTransformer();

        self::assertSame(['a'], $t->transform('a'));
        self::assertSame(['a', 'b'], $t->transform(['a', 'b']), 'an array is already the target shape');
    }

    public function testWrapArrayTurnsEmptyInputIntoAnEmptyList(): void
    {
        // [''] would post one blank entry to a repeatable CRM field; [] posts none.
        $t = new WrapArrayTransformer();

        self::assertSame([], $t->transform(null));
        self::assertSame([], $t->transform(''));
    }

    // ── callback ─────────────────────────────────────────────────────────────

    public function testCallbackInvokesTheRegisteredClosure(): void
    {
        $t = new CallbackTransformer();
        $t->registerCallback('shout', static fn (mixed $v): string => strtoupper((string) $v) . '!');

        self::assertSame('HI!', $t->transform('hi', ['name' => 'shout']));
    }

    public function testCallbackReceivesTheParams(): void
    {
        $t = new CallbackTransformer();
        $t->registerCallback('suffix', static fn (mixed $v, array $p): string => (string) $v . ($p['with'] ?? ''));

        self::assertSame('a-b', $t->transform('a', ['name' => 'suffix', 'with' => '-b']));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unresolvedCallbackProvider(): iterable
    {
        yield 'unknown name' => [['name' => 'nope']];
        yield 'no name given' => [[]];
    }

    /**
     * An unresolved callback passes the value through rather than throwing.
     *
     * Pinned because it is the transformer's most surprising behaviour: a typo in
     * `name` is not an error, it is a silently unmapped field. Anyone changing
     * this to throw should do so deliberately.
     *
     * @param array<string, mixed> $params
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unresolvedCallbackProvider')]
    public function testAnUnresolvedCallbackPassesTheValueThrough(array $params): void
    {
        $t = new CallbackTransformer();
        $t->registerCallback('known', static fn (): string => 'y');

        self::assertSame('x', $t->transform('x', $params));
    }

    public function testEveryTransformerReportsItsConfigName(): void
    {
        // The name is the string a mapping file uses; a rename is a config break.
        self::assertSame('default_value', (new DefaultValueTransformer())->getName());
        self::assertSame('prefix', (new PrefixTransformer())->getName());
        self::assertSame('strip_prefix', (new StripPrefixTransformer())->getName());
        self::assertSame('url', (new UrlTransformer())->getName());
        self::assertSame('string_case', (new StringCaseTransformer())->getName());
        self::assertSame('wrap_array', (new WrapArrayTransformer())->getName());
        self::assertSame('callback', (new CallbackTransformer())->getName());
    }
}
