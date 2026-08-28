<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping;

use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Mapping\YamlMappingLoader;
use PHPUnit\Framework\TestCase;

/**
 * Every rejection the mapping loader can produce.
 *
 * A mapping file is customer-authored config, and the loader is the only thing
 * standing between a typo and a malformed CRM write. Each branch here is a
 * message someone reads at 2am, so the test asserts the message identifies the
 * problem — not merely that something threw.
 */
final class YamlMappingLoaderValidationTest extends TestCase
{
    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMappingProvider(): iterable
    {
        yield 'not a YAML mapping' => [
            "just a scalar\n",
            'File must contain a YAML mapping',
        ];

        yield 'missing entity' => [
            "lookup_field: name\nmappings:\n  - { cc_field: a, crm_field: b }\n",
            'Missing or invalid "entity" key',
        ];

        yield 'entity is not a string' => [
            "entity: [contact]\nlookup_field: name\nmappings:\n  - { cc_field: a, crm_field: b }\n",
            'Missing or invalid "entity" key',
        ];

        yield 'missing lookup_field' => [
            "entity: contact\nmappings:\n  - { cc_field: a, crm_field: b }\n",
            'Missing or invalid "lookup_field" key',
        ];

        yield 'no rules at all' => [
            "entity: contact\nlookup_field: name\n",
            'Missing or invalid "mappings" key',
        ];

        yield 'both legacy and structured sections' => [
            "entity: contact\nlookup_field: name\nmappings:\n  - { cc_field: a, crm_field: b }\n"
            . "default:\n  mappings:\n    - { cc_field: c, crm_field: d }\n",
            'not both',
        ];

        yield 'default without a mappings list' => [
            "entity: contact\nlookup_field: name\ndefault:\n  rules: []\n",
            '"default" must contain a "mappings" list',
        ];

        yield 'types is not a map' => [
            "entity: activity\nlookup_field: x\ndefault:\n  mappings:\n    - { cc_field: a, crm_field: x }\n"
            . "types: notamap\n",
            '"types" must be a map',
        ];

        yield 'a type without a mappings list' => [
            "entity: activity\nlookup_field: x\ndefault:\n  mappings:\n    - { cc_field: a, crm_field: x }\n"
            . "types:\n  call:\n    rules: []\n",
            '"types.call" must contain a "mappings" list',
        ];

        yield 'mappings is not a list' => [
            "entity: contact\nlookup_field: name\nmappings: notalist\n",
            'must be a list',
        ];

        yield 'a keyed mappings map is read positionally and rejected' => [
            "entity: contact\nlookup_field: name\nmappings:\n  a: b\n",
            'must be an array',
        ];

        yield 'a rule that is not an array' => [
            "entity: contact\nlookup_field: name\nmappings:\n  - scalar\n",
            'must be an array',
        ];

        yield 'rule without cc_field' => [
            "entity: contact\nlookup_field: name\nmappings:\n  - { crm_field: b }\n",
            'missing or invalid "cc_field"',
        ];

        yield 'rule without crm_field or a static value' => [
            "entity: contact\nlookup_field: name\nmappings:\n  - { cc_field: a }\n",
            'missing or invalid "crm_field"',
        ];

        yield 'transformer without a name' => [
            "entity: contact\nlookup_field: name\nmappings:\n"
            . "  - cc_field: a\n    crm_field: b\n    transformers:\n      - { params: {} }\n",
            'invalid transformer definition',
        ];

        yield 'unknown multi_value strategy' => [
            "entity: contact\nlookup_field: name\nmappings:\n"
            . "  - cc_field: a\n    crm_field: b\n    multi_value:\n      strategy: sideways\n",
            'invalid multi_value strategy "sideways"',
        ];

        yield 'incomplete relation block' => [
            "entity: contact\nlookup_field: name\nmappings:\n"
            . "  - cc_field: a\n    crm_field: b\n    relation:\n      entity: account\n",
            'relation requires entity, resolve_from, and resolve_to',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidMappingProvider')]
    public function testAnInvalidMappingFileIsRejectedWithAMessageThatNamesTheProblem(
        string $yaml,
        string $expected,
    ): void {
        try {
            (new YamlMappingLoader())->load($this->write($yaml));
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testAStaticValueRuleNeedsNoCrmField(): void
    {
        // `value:` makes the rule a constant, so there is no CRM field to read
        // from — this is the one legitimate way to omit crm_field.
        $collection = (new YamlMappingLoader())->load($this->write(
            "entity: contact\nlookup_field: name\nmappings:\n  - { cc_field: source, value: crm-import }\n",
        ));

        self::assertTrue($collection->mappings[0]->hasStaticValue);
        self::assertSame('crm-import', $collection->mappings[0]->staticValue);
    }

    public function testAMissingFileIsReportedAsSuch(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        (new YamlMappingLoader())->load('/no/such/mapping.yaml');
    }

    private function write(string $yaml): string
    {
        $base = tempnam(sys_get_temp_dir(), 'mapval_');
        @unlink($base);
        $file = $base . '.yaml';
        file_put_contents($file, $yaml);
        $this->tempFiles[] = $file;

        return $file;
    }
}
