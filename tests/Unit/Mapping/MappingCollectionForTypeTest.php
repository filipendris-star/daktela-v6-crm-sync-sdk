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
        $base = tempnam(sys_get_temp_dir(), 'mapping_');
        $this->tmpFile = $base . '.yaml';
        @unlink($base); // tempnam creates the extension-less file; don't leak it
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

    public function testAppendRulesSurviveTypeMerge(): void
    {
        // Two base rules accumulate into `subject` via append. The merge must not
        // dedupe them by target field — that would silently drop one whenever the
        // type carries any rules, producing different output per activity type
        // from the same mapping file.
        $collection = $this->loadYaml(<<<YAML
            entity: activity
            lookup_field: externalId
            default:
              mappings:
                - { cc_field: firstname, crm_field: subject, append: true }
                - { cc_field: lastname, crm_field: subject, append: true }
                - { cc_field: description, crm_field: note }
            types:
              call:
                mappings:
                  - { cc_field: item_answered, crm_field: done }
            YAML);

        $countSubject = fn ($rules) => count(array_filter($rules, fn ($m) => $m->crmField === 'subject'));

        // Type with rules: both append rules must survive (this was the bug).
        $call = $collection->forType('call');
        self::assertSame(2, $countSubject($call->mappings), 'append rules must survive when the type has rules');
        self::assertCount(4, $call->mappings);

        // Type without rules: identical rule set either way.
        self::assertSame(2, $countSubject($collection->forType('email')->mappings));
    }

    public function testTypeAppendRuleAddsWithoutReplacing(): void
    {
        $collection = $this->loadYaml(<<<YAML
            entity: activity
            lookup_field: externalId
            default:
              mappings:
                - { cc_field: firstname, crm_field: subject, append: true }
            types:
              call:
                mappings:
                  - { cc_field: item_direction, crm_field: subject, append: true }
            YAML);

        $call = $collection->forType('call');
        $subjectRules = array_values(array_filter($call->mappings, fn ($m) => $m->crmField === 'subject'));

        self::assertCount(2, $subjectRules, 'a type append rule accumulates alongside the base one');
        self::assertSame('firstname', $subjectRules[0]->ccField);
        self::assertSame('item_direction', $subjectRules[1]->ccField);
    }

    public function testNonAppendTypeRuleStillOverridesBase(): void
    {
        // The append fix must not regress the core contract: a non-append type
        // rule replaces the non-append base rule with the same target, in place.
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
                  - { cc_field: item_direction, crm_field: subject }
            YAML);

        $call = $collection->forType('call');

        self::assertCount(2, $call->mappings);
        self::assertSame('item_direction', $call->mappings[0]->ccField, 'type rule replaces base subject rule in place');
        self::assertSame('note', $call->mappings[1]->crmField);
    }
}
