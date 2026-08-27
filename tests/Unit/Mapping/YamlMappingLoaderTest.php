<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping;

use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Mapping\MultiValueStrategy;
use Daktela\CrmSync\Mapping\YamlMappingLoader;
use PHPUnit\Framework\TestCase;

final class YamlMappingLoaderTest extends TestCase
{
    private YamlMappingLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new YamlMappingLoader();
    }

    public function testLoadContactsMappingFile(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        self::assertSame('contact', $collection->entityType);
        self::assertSame('email', $collection->lookupField);
        self::assertCount(3, $collection->mappings);
    }

    public function testMappingFieldValues(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        $first = $collection->mappings[0];
        self::assertSame('title', $first->ccField);
        self::assertSame('full_name', $first->crmField);
    }

    public function testMappingWithTransformers(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        // Third mapping has phone_normalize transformer
        $phone = $collection->mappings[2];
        self::assertCount(1, $phone->transformers);
        self::assertSame('phone_normalize', $phone->transformers[0]['name']);
        self::assertSame('e164', $phone->transformers[0]['params']['format']);
    }

    public function testFileNotFoundThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $this->loader->load('/nonexistent/file.yaml');
    }

    public function testLoadMappingWithRelation(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts_with_relation.yaml');

        // Fourth mapping has a relation config
        $accountField = $collection->mappings[3];
        self::assertSame('account', $accountField->ccField);
        self::assertSame('company_id', $accountField->crmField);
        self::assertNotNull($accountField->relation);
        self::assertSame('account', $accountField->relation->entity);
        self::assertSame('id', $accountField->relation->resolveFrom);
        self::assertSame('name', $accountField->relation->resolveTo);
    }

    public function testLoadMappingWithMultiValue(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts_with_relation.yaml');

        // Fifth mapping has multi_value config
        $tagsField = $collection->mappings[4];
        self::assertSame('customFields.tags', $tagsField->ccField);
        self::assertSame('tags', $tagsField->crmField);
        self::assertNotNull($tagsField->multiValue);
        self::assertSame(MultiValueStrategy::Split, $tagsField->multiValue->strategy);
        self::assertSame(',', $tagsField->multiValue->separator);
    }

    public function testLoadMappingWithoutRelationOrMultiValue(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        $first = $collection->mappings[0];
        self::assertNull($first->relation);
        self::assertNull($first->multiValue);
    }

    public function testContactsWithRelationHasCorrectCount(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts_with_relation.yaml');

        self::assertCount(5, $collection->mappings);
    }

    public function testEmptyDefaultAlongsidePopulatedTypesLoads(): void
    {
        // The UI can emit an empty `default:` next to populated `types:` — that
        // must load with no base rules, mirroring the top-level empty-`mappings:`
        // tolerance, not throw '"default" must contain a "mappings" list'.
        $base = tempnam(sys_get_temp_dir(), 'mapping_');
        $tmpFile = $base . '.yaml';
        @unlink($base); // tempnam creates the extension-less file; don't leak it
        file_put_contents($tmpFile, <<<YAML
            entity: activity
            lookup_field: externalId
            default: {}
            types:
              call:
                mappings:
                  - { cc_field: item_answered, crm_field: done }
            YAML);

        try {
            $collection = (new YamlMappingLoader())->load($tmpFile);
        } finally {
            unlink($tmpFile);
        }

        self::assertSame([], $collection->mappings);
        self::assertCount(1, $collection->forType('call')->mappings);
    }

    /**
     * Regression: an unknown timezone must be rejected at LOAD time.
     *
     * Left to transform time it throws per record — and because
     * DateFormatTransformer returns early for empty values, only the records
     * carrying a date failed. A partial batch failure advances the watermark,
     * so one typo silently and permanently skipped exactly the records it
     * dropped. Every other config value in this release is validated at load;
     * so is this one.
     */
    public function testUnknownTimezoneIsRejectedAtLoadTime(): void
    {
        $file = $this->writeTempMapping(<<<'YAML'
            entity: activity
            lookup_field: external_id
            mappings:
              - cc_field: time
                crm_field: due_date
                transformers:
                  - name: date_format
                    params: { from: 'Y-m-d', to: 'c', to_tz: 'Europe/Praha' }
            YAML);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/unknown timezone "Europe\/Praha" for "to_tz"/');
        $this->loader->load($file);
    }

    public function testAValidTimezoneStillLoads(): void
    {
        $file = $this->writeTempMapping(<<<'YAML'
            entity: activity
            lookup_field: external_id
            mappings:
              - cc_field: time
                crm_field: due_date
                transformers:
                  - name: date_format
                    params: { from: 'Y-m-d', to: 'c', to_tz: 'Europe/Prague', from_tz: 'UTC' }
            YAML);

        $collection = $this->loader->load($file);

        self::assertSame('Europe/Prague', $collection->mappings[0]->transformers[0]['params']['to_tz']);
    }

    /**
     * Regression on the fix itself: validating against
     * DateTimeZone::listIdentifiers() would have rejected these, because that
     * list holds only canonical IANA names while the constructor also accepts
     * abbreviations and fixed offsets. Rejecting them would break configs that
     * work today.
     */
    public function testAbbreviationsAndOffsetsAreAcceptedAsTimezones(): void
    {
        foreach (['CET', 'GMT', '+02:00', '-0500', 'UTC'] as $tz) {
            $file = $this->writeTempMapping(sprintf(<<<'YAML'
                entity: activity
                lookup_field: external_id
                mappings:
                  - cc_field: time
                    crm_field: due_date
                    transformers:
                      - name: date_format
                        params: { from: 'Y-m-d', to: 'c', to_tz: '%s' }
                YAML, $tz));

            $collection = $this->loader->load($file);
            self::assertSame($tz, $collection->mappings[0]->transformers[0]['params']['to_tz']);
        }
    }

    /**
     * `from_tz` is validated too, not just `to_tz`.
     *
     * Every other timezone test puts the bad zone in `to_tz`, so narrowing the
     * loop to `['to_tz']` left the suite green while `from_tz` typos sailed
     * through to fail at transform time.
     */
    public function testAnUnknownFromTimezoneIsRejectedAtLoadTime(): void
    {
        $file = $this->writeTempMapping(<<<'YAML'
            entity: activity
            lookup_field: external_id
            mappings:
              - cc_field: time
                crm_field: due_date
                transformers:
                  - name: date_format
                    params: { from: 'Y-m-d', to: 'c', from_tz: 'Europe/Praha' }
            YAML);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/unknown timezone "Europe\/Praha" for "from_tz"/');
        $this->loader->load($file);
    }

    /**
     * A non-scalar zone must be rejected before the string cast.
     *
     * Without the type check the cast raises "Array to string conversion", which
     * a host promoting warnings to ErrorException surfaces instead of the
     * ConfigurationException the rest of this loader guarantees. The reasoning
     * was in a four-line comment and nothing held it.
     */
    public function testANonScalarTimezoneIsRejectedWithoutAConversionWarning(): void
    {
        $file = $this->writeTempMapping(<<<'YAML'
            entity: activity
            lookup_field: external_id
            mappings:
              - cc_field: time
                crm_field: due_date
                transformers:
                  - name: date_format
                    params: { from: 'Y-m-d', to: 'c', to_tz: ['a', 'b'] }
            YAML);

        set_error_handler(static function (int $no, string $msg): bool {
            throw new \RuntimeException('PHP warning leaked: ' . $msg);
        }, E_WARNING);

        try {
            $this->loader->load($file);
            self::fail('expected rejection');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('must be a timezone name', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    private function writeTempMapping(string $yaml): string
    {
        $base = tempnam(sys_get_temp_dir(), 'mapping_');
        @unlink($base); // tempnam creates the extension-less file; don't leak it
        $file = $base . '.yaml';
        file_put_contents($file, $yaml);
        $this->tempFiles[] = $file;

        return $file;
    }

    /** @var string[] */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }
}
