<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\AutoCreateContactConfig;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\WebhookSync;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebhookSyncTest extends TestCase
{
    public function testSyncContactSuccess(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')
            ->with('crm-1')
            ->willReturn(Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']));

        $ccAdapter->method('upsertContact')
            ->willReturn(new UpsertResult(Contact::fromArray(['id' => 'cc-1'])));

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncContact('crm-1');

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status);
    }

    public function testSyncContactNotFound(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')->willReturn(null);

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncContact('nonexistent');

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(SyncStatus::Skipped, $result->getRecords()[0]->status);
    }

    public function testSyncActivitySuccess(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $activity = Activity::fromArray([
            'id' => 'call-1',
            'activity_type' => 'call',
            'name' => 'call-1',
            'title' => 'Test call',
        ]);

        $ccAdapter->method('findActivity')
            ->with('call-1', ActivityType::Call)
            ->willReturn($activity);

        $crmAdapter->method('upsertActivity')
            ->willReturn(Activity::fromArray(['id' => 'crm-act-1']));

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status);
    }

    public function testSyncActivityAppliesPerTypeMappingRules(): void
    {
        // The webhook and batch paths write the same CRM records, so their mappings
        // must agree — a per-type rule applying on one path and not the other would
        // leave the CRM holding whichever payload happened to arrive last.
        $activity = Activity::fromArray([
            'id' => 'call-1',
            'activity_type' => 'call',
            'name' => 'call-1',
            'title' => 'Missed call',
            'item_call_state' => 'in_missed',
        ]);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('findActivity')->willReturn($activity);

        $payload = null;
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturnCallback(
            function (string $lookupField, Activity $mapped) use (&$payload): Activity {
                $payload = $mapped->getData();

                return Activity::fromArray(['id' => 'crm-act-1']);
            },
        );

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfigWithActivityTypeRules(),
            new NullLogger(),
        );

        $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertIsArray($payload);
        self::assertArrayHasKey('done', $payload, 'per-type rule must be applied on the webhook path');
        self::assertSame(0, $payload['done'], 'missed inbound call must map to done = 0');
    }

    public function testWebhookRefusesToWriteAnEmptyPayloadForAnUnmappedType(): void
    {
        // The webhook path maps through the same forType() rules as the batch
        // path, so a types-only mapping produces an empty payload here too. It
        // must refuse to write rather than create a blank CRM record.
        $activity = Activity::fromArray(['id' => 'sms-1', 'activity_type' => 'sms', 'name' => 'sms-1', 'title' => 'Text']);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('findActivity')->willReturn($activity);

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('upsertActivity');
        $crmAdapter->expects(self::never())->method('createActivity');

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Sms]),
            ],
            mappings: ['activity' => new MappingCollection('activity', 'external_id', [], [
                'call' => [new FieldMapping('title', 'subject')],
            ])],
        );

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
        );

        $result = $webhookSync->syncActivity('sms-1', ActivityType::Sms);

        self::assertSame(1, $result->getFailedCount());
        self::assertStringContainsString('empty payload', (string) $result->getFailedRecords()[0]->errorMessage);
    }

    /**
     * Restores the multi-event coverage that went with the ledger tests.
     *
     * One call emits call_create → call_answer → call_close, and docs/06 tells
     * operators to register *_create. The adapter's upsert is the only
     * thing keeping those three events in one CRM record, so this pins both
     * halves: a CRM that can find the record updates it in place, and a CRM that
     * cannot creates one per event — the documented limitation, asserted so it
     * stays a visible number rather than a caveat.
     */
    public function testAMultiEventCallLandsInOneRecordOnASearchableCrm(): void
    {
        $crm = new SearchableActivityCrmAdapter();
        $sync = $this->webhookSyncForSearchable($crm);

        foreach (['Call started', 'Call answered', 'Call finished'] as $title) {
            $crm->nextTitle = $title;
            $sync->syncActivity('call-1', ActivityType::Call);
        }

        self::assertCount(1, $crm->created, 'one CRM record for the whole call');
        self::assertCount(2, $crm->updated, 'the two follow-up events update it');
        self::assertSame('Call finished', $crm->storedTitle, 'and the CRM holds the latest payload');
    }

    public function testTheSameCallBecomesThreeRecordsOnACrmThatCannotSearch(): void
    {
        $crm = new RecordingActivityCrmAdapter(); // findActivityByLookup() returns null
        $sync = $this->webhookSyncFor($crm);

        foreach (['Call started', 'Call answered', 'Call finished'] as $title) {
            $crm->nextTitle = $title;
            $sync->syncActivity('call-1', ActivityType::Call);
        }

        self::assertCount(3, $crm->created, 'one CRM record per webhook event');
        self::assertCount(0, $crm->updated);
    }

    /**
     * The webhook path must refuse an undedupable record too.
     *
     * It shares the batch path's only duplicate protection — the adapter's
     * upsert, keyed on the mapping's lookup_field — so a payload carrying no
     * value there would create a new CRM record on every event of a call.
     * Pinned separately because the two paths carry their own copy of the check.
     *
     * Reported as a Failed record rather than aborting, unlike the batch path:
     * this handles one event and keeps no watermark, so there is nothing to
     * protect by aborting and the caller needs the failure in the response.
     *
     * @param mixed $nameValue the source value the lookup rule reads
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableLookupValues')]
    public function testAnUnusableLookupValueIsRefusedOnTheWebhookPath(mixed $nameValue, string $why): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('findActivity')->willReturn(Activity::fromArray([
            'id' => 'call-1', 'activity_type' => 'call', 'name' => $nameValue, 'title' => 'T',
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('upsertActivity');

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com', accessToken: 't', database: 'd', batchSize: 100,
            entities: ['activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'a.yaml', [ActivityType::Call])],
            mappings: ['activity' => new MappingCollection('activity', 'external_id', [
                new FieldMapping('name', 'external_id'),
            ])],
        );

        $sync = new WebhookSync($ccAdapter, $crmAdapter, new FieldMapper(TransformerRegistry::withDefaults()), $config, new NullLogger());
        $result = $sync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(1, $result->getFailedCount(), $why);
        self::assertStringContainsString('cannot dedupe', (string) $result->getRecords()[0]->errorMessage);
    }

    /**
     * An adapter that returns an empty id must report `null`, not `''`.
     *
     * "No id" and "the empty string" are the same fact, and a caller reconciling
     * a Failed record checks for null. Covered by a ledger test that was deleted
     * with the ledger; the line survived, its coverage did not.
     */
    public function testAnEmptyCrmIdIsReportedAsUnknownRatherThanAsAnId(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('findActivity')->willReturn(Activity::fromArray([
            'id' => 'call-1', 'activity_type' => 'call', 'name' => 'call-1', 'title' => 'T',
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => '']));

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com', accessToken: 't', database: 'd', batchSize: 100,
            entities: ['activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'a.yaml', [ActivityType::Call])],
            mappings: ['activity' => new MappingCollection('activity', 'external_id', [
                new FieldMapping('name', 'external_id'),
            ])],
        );

        $sync = new WebhookSync($ccAdapter, $crmAdapter, new FieldMapper(TransformerRegistry::withDefaults()), $config, new NullLogger());

        self::assertNull($sync->syncActivity('call-1', ActivityType::Call)->getRecords()[0]->targetId);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function unusableLookupValues(): iterable
    {
        yield 'absent'      => [null, 'no value at all'];
        yield 'empty string' => ['', 'the adapter would search for "" and create every time'];
        yield 'array'        => [['a', 'b'], 'no CRM can look a record up by a list'];
    }

    public function testAnActivityWithNoLookupValueIsRefusedOnTheWebhookPath(): void
    {
        $activity = Activity::fromArray([
            'id' => 'call-1', 'activity_type' => 'call', 'name' => 'call-1', 'title' => 'Test',
        ]);
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('findActivity')->willReturn($activity);

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('upsertActivity');
        $crmAdapter->expects(self::never())->method('createActivity');

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 't',
            database: 'd',
            batchSize: 100,
            entities: ['activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'a.yaml', [ActivityType::Call])],
            // lookup_field names a cc_field: nothing in the CRM payload carries it.
            mappings: ['activity' => new MappingCollection('activity', 'name', [new FieldMapping('name', 'external_id')])],
        );

        $sync = new WebhookSync($ccAdapter, $crmAdapter, new FieldMapper(TransformerRegistry::withDefaults()), $config, new NullLogger());
        $result = $sync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(1, $result->getFailedCount());
        self::assertStringContainsString('cannot dedupe', (string) $result->getRecords()[0]->errorMessage);
    }

    private function webhookSyncForSearchable(SearchableActivityCrmAdapter $crm): WebhookSync
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('findActivity')->willReturnCallback(
            fn (): Activity => Activity::fromArray([
                'id' => 'call-1',
                'activity_type' => 'call',
                'name' => 'call-1',
                'title' => $crm->nextTitle,
            ]),
        );

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crm,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        return $webhookSync;
    }

    private function webhookSyncFor(RecordingActivityCrmAdapter $crm): WebhookSync
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('findActivity')->willReturnCallback(
            fn (): Activity => Activity::fromArray([
                'id' => 'call-1',
                'activity_type' => 'call',
                'name' => 'call-1',
                'title' => $crm->nextTitle,
            ]),
        );

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crm,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        return $webhookSync;
    }

    public function testSyncContactHandlesException(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')->willThrowException(new \RuntimeException('CRM error'));

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncContact('crm-1');

        self::assertSame(1, $result->getFailedCount());
        self::assertSame('CRM error', $result->getFailedRecords()[0]->errorMessage);
    }

    public function testSyncAccountAutoCreatesContact(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findAccount')
            ->with('acc-1')
            ->willReturn(Account::fromArray([
                'id' => 'acc-1',
                'company_name' => 'Acme',
                'external_id' => 'acme',
                'email' => 'info@acme.com',
            ]));

        $ccAdapter->method('upsertAccount')
            ->willReturn(new UpsertResult(Account::fromArray(['id' => 'cc-acc-1', 'name' => 'acme'])));

        // Auto-contact doesn't exist yet
        $ccAdapter->method('findContactBy')->willReturn(null);

        $ccAdapter->expects(self::once())->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => new UpsertResult(Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-contact-auto']),
            ), created: true));

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfigWithAutoContact(),
            new NullLogger(),
        );

        $result = $webhookSync->syncAccount('acc-1');

        self::assertSame(2, $result->getTotalCount());
        self::assertSame('account', $result->getRecords()[0]->entityType);
        self::assertSame('contact', $result->getRecords()[1]->entityType);
        self::assertSame(SyncStatus::Created, $result->getRecords()[1]->status);
    }

    public function testSyncAccountNoAutoContactWhenNotConfigured(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findAccount')
            ->with('acc-1')
            ->willReturn(Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']));

        $ccAdapter->method('upsertAccount')
            ->willReturn(new UpsertResult(Account::fromArray(['id' => 'cc-acc-1'])));

        $ccAdapter->expects(self::never())->method('upsertContact');

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncAccount('acc-1');

        self::assertSame(1, $result->getTotalCount());
    }

    private function createConfigWithActivityTypeRules(): SyncConfiguration
    {
        $activityMapping = new MappingCollection(
            'activity',
            'external_id',
            [
                new FieldMapping('name', 'external_id'),
                new FieldMapping('title', 'subject'),
            ],
            [
                'call' => [
                    new FieldMapping('item_call_state', 'done', transformers: [
                        ['name' => 'value_map', 'params' => ['map' => ['in_missed' => 0], 'default' => 1]],
                    ]),
                ],
            ],
        );

        return new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call]),
            ],
            mappings: ['activity' => $activityMapping],
        );
    }

    private function createConfig(): SyncConfiguration
    {
        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
        ]);

        $accountMapping = new MappingCollection('account', 'name', [
            new FieldMapping('title', 'company_name'),
            new FieldMapping('name', 'external_id'),
        ]);

        $activityMapping = new MappingCollection('activity', 'external_id', [
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

    private function createConfigWithAutoContact(): SyncConfiguration
    {
        $accountMapping = new MappingCollection('account', 'name', [
            new FieldMapping('title', 'company_name'),
            new FieldMapping('name', 'external_id'),
        ]);

        $autoContactMapping = new MappingCollection('contact', 'name', [
            new FieldMapping('title', 'company_name'),
            new FieldMapping('name', 'external_id'),
            new FieldMapping('email', 'email'),
            new FieldMapping('number', 'phone'),
        ]);

        return new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'account' => new EntitySyncConfig(
                    enabled: true,
                    direction: SyncDirection::CrmToCc,
                    mappingFile: 'accounts.yaml',
                    autoCreateContact: new AutoCreateContactConfig(
                        mappingFile: 'account-contact.yaml',
                        skipIfExistsFields: ['email', 'number'],
                    ),
                ),
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call]),
            ],
            mappings: [
                'account' => $accountMapping,
            ],
            autoCreateContactMappings: [
                'account' => $autoContactMapping,
            ],
        );
    }
}

/**
 * CRM that cannot search activities: every
 * upsert therefore creates. Records what was created vs updated.
 */
class RecordingActivityCrmAdapter implements CrmAdapterInterface
{
    /** @var array<int, string> */
    public array $created = [];

    /** @var array<int, string> */
    public array $updated = [];

    public string $nextTitle = 'Call started';

    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        // Reference behaviour from docs/04: find-then-update, else create.
        $id = 'crm-' . (count($this->created) + 1);
        $this->created[] = $id;

        return Activity::fromArray(['id' => $id]);
    }

    public function updateActivity(string $id, Activity $activity): Activity
    {
        $this->updated[] = $id;

        return Activity::fromArray(['id' => $id]);
    }

    public function createActivity(Activity $activity): Activity
    {
        $id = 'crm-' . (count($this->created) + 1);
        $this->created[] = $id;

        return Activity::fromArray(['id' => $id]);
    }

    public function findActivityByLookup(string $field, string $value): ?Activity
    {
        return null; // no server-side activity search
    }

    public function findActivity(string $id): ?Activity
    {
        return null;
    }

    public function findContact(string $id): ?Contact
    {
        return null;
    }

    public function findContactByLookup(string $field, string $value): ?Contact
    {
        return null;
    }

    public function iterateContacts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from [];
    }

    public function findAccount(string $id): ?Account
    {
        return null;
    }

    public function findAccountByLookup(string $field, string $value): ?Account
    {
        return null;
    }

    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from [];
    }

    public function searchContacts(string $query): \Generator
    {
        yield from [];
    }

    public function searchAccounts(string $query): \Generator
    {
        yield from [];
    }

    public function iterateCustomEntity(string $entityName, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from [];
    }

    public function findCustomEntity(string $entityName, string $id): ?array
    {
        return null;
    }

    public function findCustomEntityByLookup(string $entityName, string $field, string $value): ?array
    {
        return null;
    }

    public function ping(): bool
    {
        return true;
    }
}

/** CRM that CAN find activities: its upsert updates the existing record in place. */
final class SearchableActivityCrmAdapter extends RecordingActivityCrmAdapter
{
    public ?string $storedTitle = null;

    private ?string $storedId = null;

    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $existing = $this->findActivityByLookup($lookupField, (string) $activity->get($lookupField));

        if ($existing !== null) {
            $this->updated[] = (string) $existing->getId();
            $this->storedTitle = (string) $activity->get('subject');

            return $existing;
        }

        $this->storedId = 'crm-' . (count($this->created) + 1);
        $this->created[] = $this->storedId;
        $this->storedTitle = (string) $activity->get('subject');

        return Activity::fromArray(['id' => $this->storedId]);
    }

    public function findActivityByLookup(string $field, string $value): ?Activity
    {
        return $this->storedId === null ? null : Activity::fromArray(['id' => $this->storedId]);
    }
}

