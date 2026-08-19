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
}
