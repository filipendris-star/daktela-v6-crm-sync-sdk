<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping;

use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\MultiValueConfig;
use Daktela\CrmSync\Mapping\MultiValueStrategy;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;

final class FieldMapperTest extends TestCase
{
    private FieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FieldMapper(TransformerRegistry::withDefaults());
    }

    public function testSimpleCrmToCcMapping(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
        ]);

        // CRM entity with CRM field names
        $entity = Contact::fromArray([
            'full_name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame('John Doe', $result['title']);
        self::assertSame('john@example.com', $result['email']);
    }

    public function testCcToCrmMapping(): void
    {
        $collection = new MappingCollection('activity', 'name', [
            new FieldMapping('name', 'external_id'),
            new FieldMapping('title', 'subject'),
        ]);

        // CC entity with CC field names
        $entity = Contact::fromArray([
            'name' => 'call-123',
            'title' => 'Incoming call',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CcToCrm);

        self::assertSame('call-123', $result['external_id']);
        self::assertSame('Incoming call', $result['subject']);
    }

    public function testDotNotationRead(): void
    {
        $collection = new MappingCollection('account', 'name', [
            new FieldMapping('customFields.industry', 'industry'),
        ]);

        $entity = Contact::fromArray([
            'customFields' => ['industry' => 'Technology'],
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CcToCrm);

        self::assertSame('Technology', $result['industry']);
    }

    public function testDotNotationWrite(): void
    {
        $collection = new MappingCollection('account', 'name', [
            new FieldMapping('customFields.industry', 'industry'),
        ]);

        $entity = Contact::fromArray([
            'industry' => 'Finance',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame('Finance', $result['customFields']['industry']);
    }

    public function testTransformerChain(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('number', 'phone', [
                ['name' => 'phone_normalize', 'params' => ['format' => 'e164']],
            ]),
        ]);

        $entity = Contact::fromArray([
            'phone' => '(420) 123-456-789',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame('+420123456789', $result['number']);
    }

    public function testNullValuePassedThrough(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
        ]);

        $entity = Contact::fromArray([]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertArrayHasKey('title', $result);
        self::assertNull($result['title']);
    }

    public function testRelationResolution(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                ccField: 'account',
                crmField: 'company_id',
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);

        $entity = Contact::fromArray(['company_id' => 'crm-acc-123']);

        $relationMaps = [
            'account' => ['crm-acc-123' => 'acme', 'crm-acc-456' => 'globex'],
        ];

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, $relationMaps);

        self::assertSame('acme', $result['account']);
    }

    public function testRelationResolutionForIntegerForeignKey(): void
    {
        // Numeric-id CRMs (Pipedrive, HubSpot, …) hand back integer foreign keys;
        // the resolver must still consult the relation map (whose keys are strings)
        // rather than writing the raw int. Regression guard for the is_string()
        // short-circuit that broke Pipedrive contact→account links.
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                ccField: 'account',
                crmField: 'org_id',
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);

        $entity = Contact::fromArray(['org_id' => 6]);

        $relationMaps = [
            'account' => ['6' => 'pipedrive_6'],
        ];

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, $relationMaps);

        self::assertSame('pipedrive_6', $result['account']);
    }

    public function testRelationResolutionFallsBackToOriginalValue(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                ccField: 'account',
                crmField: 'company_id',
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);

        $entity = Contact::fromArray(['company_id' => 'unknown-id']);

        $relationMaps = [
            'account' => ['crm-acc-123' => 'acme'],
        ];

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, $relationMaps);

        // Falls back to original value when not found in map
        self::assertSame('unknown-id', $result['account']);
    }

    public function testRelationResolutionSkipsNullValues(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                ccField: 'account',
                crmField: 'company_id',
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);

        $entity = Contact::fromArray([]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, ['account' => ['x' => 'y']]);

        self::assertNull($result['account']);
    }

    public function testMultiValueSplitApplied(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                ccField: 'customFields.tags',
                crmField: 'tags',
                multiValue: new MultiValueConfig(MultiValueStrategy::Split, ','),
            ),
        ]);

        $entity = Contact::fromArray(['tags' => 'web,mobile,api']);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame(['web', 'mobile', 'api'], $result['customFields']['tags']);
    }

    public function testMultiValueJoinApplied(): void
    {
        $collection = new MappingCollection('activity', 'name', [
            new FieldMapping(
                ccField: 'customFields.interests',
                crmField: 'interests',
                multiValue: new MultiValueConfig(MultiValueStrategy::Join, ', '),
            ),
        ]);

        $entity = Contact::fromArray([
            'customFields' => ['interests' => ['sports', 'music']],
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CcToCrm);

        self::assertSame('sports, music', $result['interests']);
    }

    public function testRelationAndMultiValueCombined(): void
    {
        // Relation resolution happens before multi-value, so this tests the order
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                ccField: 'account',
                crmField: 'company_id',
                relation: new RelationConfig('account', 'id', 'name'),
            ),
            new FieldMapping(
                ccField: 'customFields.tags',
                crmField: 'tags',
                multiValue: new MultiValueConfig(MultiValueStrategy::Split, ','),
            ),
        ]);

        $entity = Contact::fromArray([
            'company_id' => 'acc-1',
            'tags' => 'vip,enterprise',
        ]);

        $relationMaps = ['account' => ['acc-1' => 'acme']];

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, $relationMaps);

        self::assertSame('acme', $result['account']);
        self::assertSame(['vip', 'enterprise'], $result['customFields']['tags']);
    }

    public function testAppendMergesArrays(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('customFields.number', 'tel1', [
                ['name' => 'wrap_array', 'params' => []],
            ]),
            new FieldMapping('customFields.number', 'tel2', [
                ['name' => 'wrap_array', 'params' => []],
            ], append: true),
        ]);

        $entity = Contact::fromArray([
            'tel1' => '+420111222333',
            'tel2' => '+420444555666',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame(['+420111222333', '+420444555666'], $result['customFields']['number']);
    }

    public function testAppendSkipsEmptyValues(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('customFields.number', 'tel1', [
                ['name' => 'wrap_array', 'params' => []],
            ]),
            new FieldMapping('customFields.number', 'tel2', [
                ['name' => 'wrap_array', 'params' => []],
            ], append: true),
        ]);

        $entity = Contact::fromArray([
            'tel1' => '+420111222333',
            'tel2' => '',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame(['+420111222333'], $result['customFields']['number']);
    }

    public function testAppendToNonExistentKeyCreatesArray(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('customFields.tags', 'extra_tag', [], append: true),
        ]);

        $entity = Contact::fromArray(['extra_tag' => 'vip']);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame(['vip'], $result['customFields']['tags']);
    }

    public function testAppendWithMultiValueJoinCollapsesAccumulatedValues(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'firstName', append: true),
            new FieldMapping(
                ccField: 'title',
                crmField: 'lastName',
                multiValue: new MultiValueConfig(MultiValueStrategy::Join, ' '),
                append: true,
            ),
        ]);

        $entity = Contact::fromArray([
            'firstName' => 'Kristýna',
            'lastName' => 'Kovandová',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame('Kristýna Kovandová', $result['title']);
    }

    public function testAppendWithMultiValueJoinFiltersNullValues(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'firstName', append: true),
            new FieldMapping(
                ccField: 'title',
                crmField: 'lastName',
                multiValue: new MultiValueConfig(MultiValueStrategy::Join, ' '),
                append: true,
            ),
        ]);

        $entity = Contact::fromArray([
            'lastName' => 'Kovandová',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame('Kovandová', $result['title']);
    }
}
