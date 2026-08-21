# Testing Your Integration

## Unit Testing with Mock Adapters

Create mock adapters to test your sync logic without real API calls:

```php
use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\SyncEngine;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MySyncTest extends TestCase
{
    public function testContactSync(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')
            ->willReturn($this->generateContacts());

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) =>
                Contact::fromArray(array_merge($contact->toArray(), ['id' => 'cc-1']))
            );

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $this->createConfig(), new NullLogger());
        $result = $engine->syncContactsBatch();

        self::assertSame(0, $result->getFailedCount());
        self::assertSame(2, $result->getTotalCount());
    }

    // Capability interfaces are feature-detected with instanceof, so a plain
    // mock of the base interface does NOT satisfy them. Mock the intersection
    // when the code under test needs one — a cc_to_crm custom entity export,
    // for instance, reads its set through SupportsEntityIterationInterface:
    //
    //   $ccAdapter = $this->createMockForIntersectionOfInterfaces([
    //       ContactCentreAdapterInterface::class,
    //       SupportsEntityIterationInterface::class,
    //   ]);

    private function generateContacts(): \Generator
    {
        yield Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']);
        yield Contact::fromArray(['id' => 'crm-2', 'full_name' => 'Jane', 'email' => 'jane@test.com']);
    }

    private function createConfig(): SyncConfiguration
    {
        // ... see examples below
    }
}
```

## Testing Field Mappings

Test mappings independently of the sync engine:

```php
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;

$mapper = new FieldMapper(TransformerRegistry::withDefaults());

$collection = new MappingCollection('contact', 'email', [
    new FieldMapping('title', 'full_name'),
    new FieldMapping('email', 'email'),
]);

// CRM entity (source for CrmToCc direction)
$entity = Contact::fromArray(['full_name' => 'John Doe', 'email' => 'john@test.com']);

$result = $mapper->map($entity, $collection, SyncDirection::CrmToCc);

self::assertSame('John Doe', $result['title']);
self::assertSame('john@test.com', $result['email']);
```

## Testing Multi-Value Fields

```php
use Daktela\CrmSync\Mapping\MultiValueConfig;
use Daktela\CrmSync\Mapping\MultiValueStrategy;

// Test split strategy
$config = new MultiValueConfig(MultiValueStrategy::Split, ',');
self::assertSame(['web', 'mobile', 'api'], $config->apply('web,mobile,api'));

// Test join strategy
$config = new MultiValueConfig(MultiValueStrategy::Join, ', ');
self::assertSame('sports, music', $config->apply(['sports', 'music']));

// Test first strategy
$config = new MultiValueConfig(MultiValueStrategy::First);
self::assertSame('first', $config->apply(['first', 'second', 'third']));
```

## Testing Relation Resolution

Test that contact-to-account references resolve correctly:

```php
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;

$mapper = new FieldMapper(TransformerRegistry::withDefaults());

$collection = new MappingCollection('contact', 'email', [
    new FieldMapping(
        ccField: 'account',
        crmField: 'company_id',
        relation: new RelationConfig(
            entity: 'account',
            resolveFrom: 'id',
            resolveTo: 'name',
        ),
    ),
]);

// CRM contact with CRM account ID
$entity = Contact::fromArray(['company_id' => 'crm-acc-123']);

// Relation map: CRM account ID → Daktela account name
$relationMaps = [
    'account' => ['crm-acc-123' => 'acme', 'crm-acc-456' => 'globex'],
];

$result = $mapper->map($entity, $collection, SyncDirection::CrmToCc, $relationMaps);

self::assertSame('acme', $result['account']);
```

## Testing fullSync() with Relations

```php
public function testFullSyncResolvesAccountReferences(): void
{
    $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
    $crmAdapter = $this->createMock(CrmAdapterInterface::class);

    // CRM accounts
    $crmAdapter->method('iterateAccounts')->willReturn((function () {
        yield Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme Corp', 'external_id' => 'acme']);
    })());

    // CRM contacts referencing account by CRM ID
    $crmAdapter->method('iterateContacts')->willReturn((function () {
        yield Contact::fromArray(['id' => 'c-1', 'full_name' => 'John', 'email' => 'john@test.com', 'company_id' => 'acc-1']);
    })());

    $ccAdapter->method('upsertAccount')->willReturnCallback(
        fn ($lookup, $account) => Account::fromArray(array_merge($account->toArray(), ['id' => 'cc-acc-1'])),
    );

    // Verify the contact is upserted with resolved account name
    $ccAdapter->expects(self::once())
        ->method('upsertContact')
        ->willReturnCallback(function ($lookup, $contact) {
            // The company_id 'acc-1' should resolve to 'acme'
            self::assertSame('acme', $contact->get('account'));
            return Contact::fromArray(array_merge($contact->toArray(), ['id' => 'cc-c-1']));
        });

    $engine = new SyncEngine($ccAdapter, $crmAdapter, $config, new NullLogger());
    $results = $engine->fullSync();

    self::assertNotNull($results->account);
    self::assertNotNull($results->contact);
    self::assertSame(0, $results->contact->getFailedCount());
}
```

## Testing Webhooks

```php
use Daktela\CrmSync\Webhook\WebhookHandler;
use Daktela\CrmSync\Webhook\WebhookPayloadParser;
use Nyholm\Psr7\ServerRequest;

$handler = new WebhookHandler(
    syncEngine: $engine,
    parser: new WebhookPayloadParser(),
    webhookSecret: 'test-secret',
    logger: new NullLogger(),
);

// Valid webhook request
$request = new ServerRequest('POST', '/webhook')
    ->withHeader('Content-Type', 'application/json')
    ->withHeader('X-Webhook-Secret', 'test-secret');

$request->getBody()->write(json_encode([
    'event' => 'call_close',
    'name' => 'call-123',
    'data' => ['title' => 'Test call'],
]));

$result = $handler->handle($request);

self::assertSame(200, $result->httpStatusCode);
```

**Testing invalid secret:**

```php
$request = new ServerRequest('POST', '/webhook')
    ->withHeader('X-Webhook-Secret', 'wrong-secret');

$result = $handler->handle($request);

self::assertSame(401, $result->httpStatusCode);
```

## Testing YAML Loading

Verify your YAML mapping files load correctly:

```php
use Daktela\CrmSync\Mapping\YamlMappingLoader;
use Daktela\CrmSync\Sync\SyncDirection;

$loader = new YamlMappingLoader();
$collection = $loader->load(__DIR__ . '/config/mappings/contacts.yaml');

self::assertSame('contact', $collection->entityType);
self::assertSame('email', $collection->lookupField);
self::assertGreaterThan(0, count($collection->mappings));
```

## Testing Custom Transformers

```php
use Daktela\CrmSync\Mapping\Transformer\ValueTransformerInterface;

class CurrencyTransformerTest extends TestCase
{
    public function testConversion(): void
    {
        $transformer = new CurrencyTransformer();

        self::assertSame('currency', $transformer->getName());
        self::assertSame(25.0, $transformer->transform(100, ['from' => 'CZK', 'to' => 'EUR']));
    }
}
```

## Integration Testing

For integration tests against a real Daktela instance:

1. Set environment variables: `DAKTELA_INSTANCE_URL`, `DAKTELA_ACCESS_TOKEN`
2. Use a test/sandbox Daktela instance
3. Create and clean up test data in setUp/tearDown

```php
$adapter = new DaktelaAdapter(
    getenv('DAKTELA_INSTANCE_URL'),
    getenv('DAKTELA_ACCESS_TOKEN'),
    getenv('DAKTELA_DATABASE') ?: 'default',
    new NullLogger(),
);

// Verify connectivity
self::assertTrue($adapter->ping());

// Test a round-trip
$contact = Contact::fromArray([
    'title' => 'Integration Test Contact',
    'email' => 'integration-test@example.com',
]);

$created = $adapter->upsertContact('email', $contact);
self::assertNotNull($created->getId());
```

## Test Configuration Helper

A reusable helper for building test configs:

```php
private function createConfig(): SyncConfiguration
{
    $contactMapping = new MappingCollection('contact', 'email', [
        new FieldMapping('title', 'full_name'),
        new FieldMapping('email', 'email'),
        new FieldMapping(
            ccField: 'account',
            crmField: 'company_id',
            relation: new RelationConfig('account', 'id', 'name'),
        ),
    ]);

    $accountMapping = new MappingCollection('account', 'name', [
        new FieldMapping('title', 'company_name'),
        new FieldMapping('name', 'external_id'),
    ]);

    $activityMapping = new MappingCollection('activity', 'name', [
        new FieldMapping('name', 'external_id'),
        new FieldMapping('title', 'subject'),
    ]);

    return new SyncConfiguration(
        instanceUrl: 'https://test.daktela.com',
        accessToken: 'test-token',
        database: 'test-db',
        batchSize: 100,
        entities: [
            'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
            'account' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'accounts.yaml'),
            'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call]),
        ],
        mappings: [
            'contact' => $contactMapping,
            'account' => $accountMapping,
            'activity' => $activityMapping,
        ],
    );
}
```
