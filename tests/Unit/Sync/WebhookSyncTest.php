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

    public function testUpdateReturningNoIdKeepsTheLedgerOnTheSameRecord(): void
    {
        // updateActivity() is not required to echo the id back, and an adapter that
        // returns the entity it was handed reports none. Deriving "was it
        // re-created?" from the returned id therefore fires on a perfectly normal
        // in-place update: the ledger's good id is overwritten with null, and every
        // later event creates another CRM activity while reporting success.
        $ledger = new WebhookLookupLedger();
        $crm = new SilentUpdateActivityCrmAdapter();
        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        $webhookSync->syncActivity('call-1', ActivityType::Call);
        $second = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(SyncStatus::Updated, $second->getRecords()[0]->status);
        self::assertSame('crm-1', $second->getRecords()[0]->targetId, 'the record is still the one the ledger names');
        self::assertCount(1, $crm->created, 'an in-place update must not create anything');
        self::assertSame(['crm-1'], $crm->updated);
        self::assertSame(1, $ledger->recordCalls, 'nothing was re-created, so nothing is re-recorded');
        self::assertSame('crm-1', $ledger->findCrmId('activity', 'call-1'), 'the recorded id survives');
    }

    public function testTransientUpdateFailureNeitherDuplicatesNorRepointsTheLedger(): void
    {
        // A 500 or a timeout means "unknown, retry" — the CRM record is still
        // there. Recovering from it by re-creating turns one outage into permanent
        // duplication and points the ledger at the copy. Only a positive
        // "does not exist" is recoverable.
        $ledger = new WebhookLookupLedger();
        $crm = new FlakyUpdateActivityCrmAdapter();
        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        $webhookSync->syncActivity('call-1', ActivityType::Call);
        $second = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(SyncStatus::Failed, $second->getRecords()[0]->status, 'retryable, not silently recovered');
        self::assertCount(1, $crm->created, 'no duplicate for a record that still exists');
        self::assertSame(1, $ledger->recordCalls);
        self::assertSame('crm-1', $ledger->findCrmId('activity', 'call-1'), 'the ledger still names the real record');
    }

    public function testRecordDeletedInTheCrmIsRecreatedAndTheLedgerRepointed(): void
    {
        // The one recoverable case: the CRM positively states the record is gone.
        // Re-create it and repoint the ledger, or every later event fails forever.
        $ledger = new WebhookLookupLedger();
        $crm = new GoneActivityCrmAdapter();
        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        $webhookSync->syncActivity('call-1', ActivityType::Call);
        $second = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(SyncStatus::Updated, $second->getRecords()[0]->status);
        self::assertSame('crm-2', $second->getRecords()[0]->targetId);
        self::assertCount(2, $crm->created, 'the deleted record was re-created');
        self::assertSame(2, $ledger->recordCalls, 'and re-recorded, exactly once');
        self::assertSame('crm-2', $ledger->findCrmId('activity', 'call-1'));
    }

    public function testFollowUpEventsOnAPlainLedgerStillReportTheCrmRecordTheyWrote(): void
    {
        // A plain ledger knows THAT the activity synced but not where, so follow-up
        // events go through the adapter's upsert — which returns a real id. Deciding
        // the reported id from "have we synced before?" rather than from the branch
        // that ran nulled it: on a searchable CRM the record it updated became
        // unreportable, and on a non-searchable one the duplicate it just created
        // was invisible in the result. RecordResult is a host's only channel for
        // "which CRM record did this land in".
        $searchable = new SearchableActivityCrmAdapter();
        $searchableSync = $this->webhookSyncForSearchable($searchable, new WebhookPlainLedger());

        $searchableSync->syncActivity('call-1', ActivityType::Call);
        $second = $searchableSync->syncActivity('call-1', ActivityType::Call);
        self::assertSame('crm-1', $second->getRecords()[0]->targetId, 'the record upsert updated');

        $plain = new RecordingActivityCrmAdapter();
        $plainSync = $this->webhookSyncFor($plain, new WebhookPlainLedger());

        $plainSync->syncActivity('call-1', ActivityType::Call);
        $secondPlain = $plainSync->syncActivity('call-1', ActivityType::Call);
        self::assertSame('crm-2', $secondPlain->getRecords()[0]->targetId, 'the duplicate must not be invisible');
    }

    public function testANullLedgerRowIsUpgradedAsSoonAsAnIdIsKnown(): void
    {
        // One truncated CRM response must not disable the update path forever. With
        // a null recorded id, findCrmId() returns null, so $knownCrmId stays null
        // and $recreated stays false — leaving the row unrevisited while every later
        // event hands back a usable id, adding one CRM record per event indefinitely.
        $ledger = new WebhookLookupLedger();
        $crm = new FirstWriteIdlessCrmAdapter();
        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        $webhookSync->syncActivity('call-1', ActivityType::Call);
        self::assertNull($ledger->findCrmId('activity', 'call-1'), 'first write named no record');

        $webhookSync->syncActivity('call-1', ActivityType::Call);
        self::assertSame('crm-2', $ledger->findCrmId('activity', 'call-1'), 'the row is upgraded');

        $third = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame('crm-2', $third->getRecords()[0]->targetId);
        self::assertCount(2, $crm->created, 'no further creates once the id is known');
        self::assertSame(['crm-2'], $crm->updated, 'the third event updated the recorded record');
    }

    public function testAnEmptyCrmIdIsRecordedAsUnknownRatherThanAsAnId(): void
    {
        // '' and null are the same fact: the write named no record. Stored as '' it
        // passes the "we already know the id" test, so the row is never upgraded and
        // every later event tries to update the CRM against an empty id — failing
        // forever instead of recovering as soon as a real id turns up.
        $ledger = new WebhookLookupLedger();
        $crm = new FirstWriteEmptyIdCrmAdapter();
        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        $webhookSync->syncActivity('call-1', ActivityType::Call);
        self::assertNull($ledger->findCrmId('activity', 'call-1'), 'empty is stored as unknown');

        $second = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(SyncStatus::Updated, $second->getRecords()[0]->status, 'recovers instead of failing');
        self::assertSame('crm-2', $ledger->findCrmId('activity', 'call-1'), 'and the row is upgraded');
    }

    public function testANotNullLedgerColumnCoercingNullToEmptyStillRecovers(): void
    {
        // The null row is normalised on the way OUT, but a store cannot always
        // keep it: a `crm_id VARCHAR NOT NULL DEFAULT ''` column returns '' where
        // null went in. Read back unnormalised, '' is !== null, so the update path
        // runs against an empty id and fails on every event while the repair
        // clause — gated on $knownCrmId === null — never arms.
        $ledger = new WebhookNotNullColumnLedger();
        $crm = new FirstWriteIdlessCrmAdapter();
        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        $webhookSync->syncActivity('call-1', ActivityType::Call);
        self::assertSame('', $ledger->findCrmId('activity', 'call-1'), 'the store coerced the null row to empty');

        $second = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(SyncStatus::Updated, $second->getRecords()[0]->status);
        self::assertSame('crm-2', $second->getRecords()[0]->targetId, 'the row is repaired rather than wedged');
        self::assertSame([], $crm->updated, 'nothing was updated against an empty id');
    }

    public function testExportWithoutAnIdIsStillRecordedSoTheBatchPathCannotDuplicate(): void
    {
        // The CRM write succeeded but the adapter named no record. Refusing to
        // record — failing the event to make the adapter's fault loud — prevents
        // nothing: follow-up events cannot update an unnamed record either way, so
        // the CRM ends up with the same duplicates. What it DOES do is leave the
        // ledger with no row, and BatchSync::exportActivityViaLedger gates on
        // hasSynced() alone, so the next batch run exports the activity AGAIN.
        // Recording "exported, id unknown" is both accurate and the only thing
        // that stops the batch path adding more copies.
        $ledger = new WebhookLookupLedger();
        $crm = new IdlessUpsertActivityCrmAdapter();
        $webhookSync = $this->webhookSyncFor($crm, $ledger);

        $result = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status);
        self::assertSame(1, $ledger->recordCalls);
        self::assertTrue($ledger->hasSynced('activity', 'call-1'), 'the batch path must see it as exported');
        self::assertNull($ledger->findCrmId('activity', 'call-1'), 'recorded honestly: exported, id unknown');
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

    public function testWebhookRefusesToWriteAnEmptyPayloadForAnUnmappedType(): void
    {
        // The webhook path maps through the same forType() rules as the batch
        // path, so a types-only mapping blanks the same records here. It must
        // refuse for the same reason: the ledger makes a webhook-written payload
        // permanent, and a later batch run skips it.
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
            mappings: ['activity' => new MappingCollection('activity', 'name', [], [
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

/**
 * A ledger backed by a NOT NULL column: the null row goes in and '' comes back.
 * This is what a plain SQL store does unless it declares crm_id nullable.
 */
final class WebhookNotNullColumnLedger extends WebhookPlainLedger implements \Daktela\CrmSync\State\SyncLedgerLookupInterface
{
    public function recordSynced(string $entityType, string $ccId, ?string $crmId): void
    {
        parent::recordSynced($entityType, $ccId, $crmId ?? '');
    }

    public function findCrmId(string $entityType, string $ccId): ?string
    {
        return $this->rows[$entityType . '|' . $ccId] ?? null;
    }
}

/** Adapter whose updateActivity() returns the entity it was handed — no id. */
final class SilentUpdateActivityCrmAdapter extends RecordingActivityCrmAdapter
{
    public function updateActivity(string $id, Activity $activity): Activity
    {
        $this->updated[] = $id;

        return $activity;
    }
}

/** Adapter whose updateActivity() fails transiently — the record still exists. */
final class FlakyUpdateActivityCrmAdapter extends RecordingActivityCrmAdapter
{
    public function updateActivity(string $id, Activity $activity): Activity
    {
        throw \Daktela\CrmSync\Exception\AdapterException::updateFailed('activity', $id, null, 'HTTP 500');
    }
}

/** Adapter whose updateActivity() reports the record no longer exists. */
final class GoneActivityCrmAdapter extends RecordingActivityCrmAdapter
{
    public function updateActivity(string $id, Activity $activity): Activity
    {
        throw \Daktela\CrmSync\Exception\RecordNotFoundException::forRecord('activity', $id);
    }
}

/** Adapter whose FIRST write response is truncated; later ones name the record. */
final class FirstWriteIdlessCrmAdapter extends RecordingActivityCrmAdapter
{
    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $id = 'crm-' . (count($this->created) + 1);
        $this->created[] = $id;

        return count($this->created) === 1 ? Activity::fromArray([]) : Activity::fromArray(['id' => $id]);
    }
}

/** Adapter whose first write returns an EMPTY id; later ones name the record. */
final class FirstWriteEmptyIdCrmAdapter extends RecordingActivityCrmAdapter
{
    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $id = 'crm-' . (count($this->created) + 1);
        $this->created[] = $id;

        return Activity::fromArray(['id' => count($this->created) === 1 ? '' : $id]);
    }
}

/** Adapter that writes successfully but names no record. */
final class IdlessUpsertActivityCrmAdapter extends RecordingActivityCrmAdapter
{
    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $this->created[] = 'unnamed';

        return Activity::fromArray([]);
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
