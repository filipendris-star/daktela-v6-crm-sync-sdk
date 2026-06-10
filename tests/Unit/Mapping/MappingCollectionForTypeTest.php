<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping;

use Daktela\CrmSync\Mapping\YamlMappingLoader;
use PHPUnit\Framework\TestCase;

final class MappingCollectionForTypeTest extends TestCase
{
    private string $tmpFile;

    protected function tearDown(): void
    {
        if (isset($this->tmpFile) && is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function loadYaml(string $yaml): \Daktela\CrmSync\Mapping\MappingCollection
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'mapping_') . '.yaml';
        file_put_contents($this->tmpFile, $yaml);

        return (new YamlMappingLoader())->load($this->tmpFile);
    }

    public function testLegacyTopLevelMappingsStillLoads(): void
    {
        $collection = $this->loadYaml(<<<YAML
            entity: activity
            lookup_field: externalId
            mappings:
              - { cc_field: title, crm_field: subject }
            YAML);

        self::assertCount(1, $collection->mappings);
        self::assertSame([], $collection->typeMappings);
        // forType on a legacy collection returns the same rules
        self::assertCount(1, $collection->forType('call')->mappings);
    }

    public function testDefaultAndTypesStructure(): void
    {
        $collection = $this->loadYaml(<<<YAML
            entity: activity
            lookup_field: externalId
            default:
              mappings:
                - { cc_field: title, crm_field: subject }
                - { cc_field: description, crm_field: note }
            types:
              call:
                mappings:
                  - { cc_field: item_answered, crm_field: done }
              sms:
                mappings:
                  - { cc_field: item_direction, crm_field: done }
                  - { cc_field: title, crm_field: subject, transformers: [{ name: string_case, params: { case: upper } }] }
            YAML);

        self::assertCount(2, $collection->mappings);
        self::assertArrayHasKey('call', $collection->typeMappings);
        self::assertArrayHasKey('sms', $collection->typeMappings);

        // call: default 2 rules + its own done rule
        $call = $collection->forType('call');
        self::assertCount(3, $call->mappings);

        // sms: done rule appended, subject rule OVERRIDES the default subject rule
        $sms = $collection->forType('sms');
        self::assertCount(3, $sms->mappings);
        $subjectRules = array_values(array_filter($sms->mappings, fn ($m) => $m->crmField === 'subject'));
        self::assertCount(1, $subjectRules);
        self::assertNotSame([], $subjectRules[0]->transformers, 'type-level subject rule must win');

        // unknown type: just the defaults
        self::assertCount(2, $collection->forType('email')->mappings);
        self::assertCount(2, $collection->forType(null)->mappings);
    }

    public function testEmptyDefaultWithTypeOnlyRule(): void
    {
        $collection = $this->loadYaml(<<<YAML
            entity: activity
            lookup_field: externalId
            default:
              mappings: []
            types:
              call:
                mappings:
                  - { cc_field: item_answered, crm_field: done }
            YAML);

        self::assertCount(0, $collection->forType('sms')->mappings);
        self::assertCount(1, $collection->forType('call')->mappings);
    }

    public function testMixingLegacyAndStructuredThrows(): void
    {
        $this->expectException(\Daktela\CrmSync\Exception\ConfigurationException::class);

        $this->loadYaml(<<<YAML
            entity: activity
            lookup_field: externalId
            mappings:
              - { cc_field: title, crm_field: subject }
            types:
              call:
                mappings: []
            YAML);
    }
}
