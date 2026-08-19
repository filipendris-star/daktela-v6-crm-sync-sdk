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


    public function testMultiEventCallUpdatesOneCrmRecordWithALookupLedger(): void
    {
        // One call emits call_create → call_answer → call_close. On a CRM that
        // cannot search activities (findActivityByLookup returns null — the very
        // reason a ledger exists), upsert would CREATE on every event. With a
        // lookup-capable ledger the follow-up events must update the record the
        // first event created: one CRM record, three writes.
        $ledger = new WebhookLookupLedger();
        $crm = new RecordingActivityCrmAdapter();

        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        foreach (['Call started', 'Call answered', 'Call closed'] as $title) {
            $result = $webhookSync->syncActivity('call-1', ActivityType::Call);
            self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status, $title);
            $crm->nextTitle = $title;
        }

        self::assertCount(1, $crm->created, 'exactly one CRM activity for one call');
        self::assertCount(2, $crm->updated, 'the two follow-up events updated it');
        self::assertSame(['crm-1', 'crm-1'], $crm->updated, 'both updates targeted the recorded CRM id');
        self::assertSame(1, $ledger->recordCalls, 'recorded once, not per event');
    }

    public function testMultiEventCallWithoutLookupLedgerFallsBackToAdapterUpsert(): void
    {
        // Documented limit, unchanged by this SDK: on a CRM that cannot search
        // activities, a plain ledger leaves the adapter's upsert unable to locate
        // the record, so each event adds one. The SDK must NOT try to avoid that by
        // skipping — that would freeze the record for every host whose ledger
        // predates the lookup interface, including on CRMs where upsert works fine
        // (see testSearchableCrmWithAPlainLedgerStillUpdatesFollowUpEvents).
        // Implementing SyncLedgerLookupInterface is the fix, proven by
        // testMultiEventCallUpdatesOneCrmRecordWithALookupLedger.
        $ledger = new WebhookPlainLedger();
        $crm = new RecordingActivityCrmAdapter();

        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        $first = $webhookSync->syncActivity('call-1', ActivityType::Call);
        $second = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(SyncStatus::Updated, $first->getRecords()[0]->status);
        self::assertSame(SyncStatus::Updated, $second->getRecords()[0]->status, 'the event is processed, not skipped');
        self::assertCount(2, $crm->created, 'the non-searchable CRM cannot dedupe — this is why the lookup ledger exists');
        self::assertSame(1, $ledger->recordCalls, 'the ledger is still recorded only once');
    }


    public function testSearchableCrmWithAPlainLedgerStillUpdatesFollowUpEvents(): void
    {
        // The dangerous case the previous fixture could not express: a CRM that CAN
        // find the activity, with a ledger that cannot return its id. Deciding from
        // the ledger's type would skip here and freeze the record; the decision must
        // come from the CRM, whose upsert updates in place.
        $ledger = new WebhookPlainLedger();
        $crm = new SearchableActivityCrmAdapter();

        $webhookSync = $this->webhookSyncForSearchable($crm, $ledger);

        foreach (['Call started', 'Call answered', 'Call closed'] as $title) {
            $crm->nextTitle = $title;
            $result = $webhookSync->syncActivity('call-1', ActivityType::Call);
            self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status, $title);
        }

        self::assertCount(1, $crm->created, 'one CRM activity for one call');
        self::assertCount(2, $crm->updated, 'follow-up events updated it instead of being skipped');
        self::assertSame('Call closed', $crm->storedTitle, 'the latest payload won');
    }

    private function webhookSyncForSearchable(SearchableActivityCrmAdapter $crm, \Daktela\CrmSync\State\SyncLedgerInterface $ledger): WebhookSync
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
        $webhookSync->setLedger($ledger);

        return $webhookSync;
    }

    private function webhookSyncFor(RecordingActivityCrmAdapter $crm, \Daktela\CrmSync\State\SyncLedgerInterface $ledger): WebhookSync
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
        $webhookSync->setLedger($ledger);

        return $webhookSync;
    }

    public function testSyncActivityRecordsInLedgerAfterUpsert(): void
    {
        // Without this, a webhook-pushed activity is upserted but never recorded,
        // and a later batch run (create-without-lookup when a ledger is set)
        // creates a second CRM record for the same activity.
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('findActivity')->willReturn(Activity::fromArray([
            'id' => 'call-1', 'activity_type' => 'call', 'name' => 'call-1', 'title' => 'Test call',
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-act-1']));

        $ledger = $this->createMock(\Daktela\CrmSync\State\SyncLedgerInterface::class);
        $ledger->method('hasSynced')->willReturn(false);
        $ledger->expects(self::once())
            ->method('recordSynced')
            ->with('activity', 'call-1', 'crm-act-1');

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );
        $webhookSync->setLedger($ledger);

        $result = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status);
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


    public function testSyncActivityAppliesPerTypeMappingRules(): void
    {
        // The webhook and batch paths write the same CRM records and cooperate
        // via the ledger (a webhook payload the ledger records is never revisited
        // by a batch run), so the webhook path must apply per-activity-type rules
        // exactly as the batch path does — otherwise it writes a permanently
        // wrong payload.
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

    private function createConfigWithActivityTypeRules(): SyncConfiguration
    {
        $activityMapping = new MappingCollection(
            'activity',
            'name',
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
 * CRM that cannot search activities (like the CRMs a ledger exists for): every
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

/** Ledger without id lookup: knows THAT a record synced, not its CRM id. */
class WebhookPlainLedger implements \Daktela\CrmSync\State\SyncLedgerInterface
{
    /** @var array<string, ?string> */
    protected array $rows = [];

    public int $recordCalls = 0;

    public function hasSynced(string $entityType, string $ccId): bool
    {
        return array_key_exists($entityType . '|' . $ccId, $this->rows);
    }

    public function recordSynced(string $entityType, string $ccId, ?string $crmId): void
    {
        $this->recordCalls++;
        $this->rows[$entityType . '|' . $ccId] = $crmId;
    }
}

/** Ledger that can hand the recorded CRM id back. */
final class WebhookLookupLedger extends WebhookPlainLedger implements \Daktela\CrmSync\State\SyncLedgerLookupInterface
{
    public function findCrmId(string $entityType, string $ccId): ?string
    {
        return $this->rows[$entityType . '|' . $ccId] ?? null;
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
