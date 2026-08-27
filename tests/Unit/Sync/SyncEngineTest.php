<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\State\SyncStateStoreInterface;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\SyncEngine;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SyncEngineTest extends TestCase
{
    public function testSyncContactsBatch(): void
    {
        $crmContacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John Doe', 'email' => 'john@example.com']),
            Contact::fromArray(['id' => 'crm-2', 'full_name' => 'Jane Doe', 'email' => 'jane@example.com']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->arrayToGenerator($crmContacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn (string $lookup, Contact $contact) => new UpsertResult(Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-' . $contact->get('email')]),
            )));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $engine->syncContactsBatch();

        self::assertSame(2, $result->getTotalCount());
        self::assertSame(0, $result->getFailedCount());
    }

    public function testSyncAccountsBatch(): void
    {
        $crmAccounts = [
            Account::fromArray(['id' => 'crm-a-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateAccounts')->willReturn($this->arrayToGenerator($crmAccounts));

        $ccAdapter->method('upsertAccount')
            ->willReturnCallback(fn (string $lookup, Account $account) => new UpsertResult(Account::fromArray(
                array_merge($account->toArray(), ['id' => 'cc-account-1']),
            )));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
        );

        $batch = $engine->syncAccountsBatch();

        self::assertSame(1, $batch->account->getTotalCount());
        self::assertSame(0, $batch->account->getFailedCount());
    }

    public function testSyncActivitiesBatch(): void
    {
        $activities = [
            // `name` included deliberately: it is the field the mapping carries into
            // the CRM as external_id, and without it the export has no dedup key.
            Activity::fromArray(['id' => 'call-1', 'name' => 'call-1', 'activity_type' => 'call', 'title' => 'Test call']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $ccAdapter->method('iterateActivities')->willReturn($this->arrayToGenerator($activities));

        $crmAdapter->method('upsertActivity')
            ->willReturnCallback(fn (string $lookup, Activity $activity) => Activity::fromArray(
                array_merge($activity->toArray(), ['id' => 'crm-act-1']),
            ));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(initialSync: 'everything'),
            new NullLogger(),
        );

        $result = $engine->syncActivitiesBatch([ActivityType::Call]);

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(0, $result->getFailedCount());

    }

    public function testSyncContactSingle(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')
            ->with('crm-1')
            ->willReturn(Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']));

        $ccAdapter->method('upsertContact')
            ->willReturn(new UpsertResult(Contact::fromArray(['id' => 'cc-1'])));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $engine->syncContact('crm-1');

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status);
    }

    public function testSyncHandlesPerRecordErrors(): void
    {
        $crmContacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->arrayToGenerator($crmContacts));

        $ccAdapter->method('upsertContact')
            ->willThrowException(new \RuntimeException('API error'));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
        );

        $batchError = null;
        $result = $engine->syncContactsBatch(function (string $type, SyncResult $batch) use (&$batchError) {
            if ($batch->getFailedCount() > 0) {
                $batchError = $batch->getFailedRecords()[0]->errorMessage;
            }
        });

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(1, $result->getFailedCount());
        self::assertSame('API error', $batchError);
    }

    public function testFullSyncRunsAllEntityTypes(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        // Return fresh generators each call to avoid "already closed" errors
        $crmAdapter->method('iterateAccounts')->willReturnCallback(fn () => $this->arrayToGenerator([
            Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
        ]));
        $crmAdapter->method('iterateContacts')->willReturnCallback(fn () => $this->arrayToGenerator([
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com', 'company_id' => 'acc-1']),
        ]));
        $ccAdapter->method('iterateActivities')->willReturnCallback(fn () => $this->arrayToGenerator([
            Activity::fromArray(['id' => 'call-1', 'activity_type' => 'call', 'name' => 'call-1', 'title' => 'Test call']),
        ]));

        $ccAdapter->method('upsertAccount')
            ->willReturnCallback(fn (string $lookup, Account $account) => new UpsertResult(Account::fromArray(
                array_merge($account->toArray(), ['id' => 'cc-acc-1']),
            )));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn (string $lookup, Contact $contact) => new UpsertResult(Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-c-1']),
            )));

        $crmAdapter->method('upsertActivity')
            ->willReturnCallback(fn (string $lookup, Activity $activity) => Activity::fromArray(
                array_merge($activity->toArray(), ['id' => 'crm-act-1']),
            ));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfigWithRelations(),
            new NullLogger(),
        );

        $results = $engine->fullSync([ActivityType::Call]);

        self::assertNotNull($results->account);
        self::assertNotNull($results->autoContact);
        self::assertNotNull($results->contact);
        self::assertNotNull($results->activity);
        self::assertSame(1, $results->account->getTotalCount());
        self::assertSame(0, $results->autoContact->getTotalCount());
        self::assertSame(1, $results->contact->getTotalCount());
        self::assertSame(1, $results->activity->getTotalCount());
        self::assertSame(0, $results->account->getFailedCount());
        self::assertSame(0, $results->contact->getFailedCount());
        self::assertSame(0, $results->activity->getFailedCount());
    }

    public function testFullSyncResolvesAccountReferences(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateAccounts')->willReturnCallback(fn () => $this->arrayToGenerator([
            Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme Corp', 'external_id' => 'acme']),
        ]));
        $crmAdapter->method('iterateContacts')->willReturnCallback(fn () => $this->arrayToGenerator([
            Contact::fromArray(['id' => 'c-1', 'full_name' => 'John', 'email' => 'john@test.com', 'company_id' => 'acc-1']),
        ]));

        $ccAdapter->method('upsertAccount')
            ->willReturnCallback(fn ($lookup, $account) => new UpsertResult(Account::fromArray(
                array_merge($account->toArray(), ['id' => 'cc-acc-1']),
            )));

        // Capture the contact that gets upserted to verify account reference is resolved
        $ccAdapter->expects(self::once())
            ->method('upsertContact')
            ->willReturnCallback(function (string $lookup, Contact $contact) {
                // The company_id 'acc-1' should be resolved to 'cc-acc-1' (the CC target ID)
                self::assertSame('cc-acc-1', $contact->get('account'));
                return new UpsertResult(Contact::fromArray(array_merge($contact->toArray(), ['id' => 'cc-c-1'])));
            });

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfigWithRelations(),
            new NullLogger(),
        );

        $results = $engine->fullSync();

        self::assertSame(0, $results->contact->getFailedCount());
    }

    public function testFullSyncToArrayReturnsKeyedResults(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateAccounts')->willReturnCallback(fn () => $this->arrayToGenerator([
            Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
        ]));
        $crmAdapter->method('iterateContacts')->willReturnCallback(fn () => $this->arrayToGenerator([]));
        $ccAdapter->method('iterateActivities')->willReturnCallback(fn () => $this->arrayToGenerator([]));

        $ccAdapter->method('upsertAccount')
            ->willReturnCallback(fn ($lookup, $account) => new UpsertResult(Account::fromArray(
                array_merge($account->toArray(), ['id' => 'cc-acc-1']),
            )));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfigWithRelations(),
            new NullLogger(),
        );

        $results = $engine->fullSync([ActivityType::Call]);
        $array = $results->toArray();

        self::assertArrayHasKey('account', $array);
        self::assertArrayHasKey('auto_contact', $array);
        self::assertArrayHasKey('contact', $array);
        self::assertArrayHasKey('activity', $array);
    }

    public function testFullSyncSkipsDisabledEntities(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        // Account should not be called since it's disabled
        $crmAdapter->expects(self::never())->method('iterateAccounts');

        $crmAdapter->method('iterateContacts')->willReturn($this->arrayToGenerator([
            Contact::fromArray(['id' => 'c-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ]));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => new UpsertResult(Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-1']),
            )));

        $config = $this->createConfigWithDisabledAccount();

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $config, new NullLogger());

        $results = $engine->fullSync();

        self::assertNull($results->account);
        self::assertNull($results->autoContact);
        self::assertNotNull($results->contact);
    }

    public function testForceFullSyncBypassesState(): void
    {
        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        // getLastSyncTime should NOT be called because forceFullSync bypasses the store
        $stateStore->expects(self::never())->method('getLastSyncTime');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateAccounts')->willReturnCallback(fn () => $this->arrayToGenerator([]));
        $crmAdapter->method('iterateContacts')->willReturnCallback(fn () => $this->arrayToGenerator([]));
        $ccAdapter->method('iterateActivities')->willReturnCallback(fn () => $this->arrayToGenerator([]));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
            null,
            $stateStore,
        );

        $engine->fullSync([ActivityType::Call], forceFullSync: true);
    }

    public function testResetStateForEntityCallsClear(): void
    {
        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->expects(self::once())->method('clear')->with('contact');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
            null,
            $stateStore,
        );

        $engine->resetState('contact');
    }

    public function testResetStateWithoutArgCallsClearAll(): void
    {
        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->expects(self::once())->method('clearAll');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
            null,
            $stateStore,
        );

        $engine->resetState();
    }

    public function testTestConnectionsSucceeds(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->expects(self::once())->method('ping')->willReturn(true);
        $ccAdapter->expects(self::once())->method('ping')->willReturn(true);

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
        );

        $engine->testConnections();
        $this->addToAssertionCount(1);
    }

    public function testTestConnectionsThrowsOnCrmFailure(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('ping')->willReturn(false);
        $ccAdapter->expects(self::never())->method('ping');

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to CRM API');
        $engine->testConnections();
    }

    public function testTestConnectionsThrowsOnCcFailure(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('ping')->willReturn(true);
        $ccAdapter->method('ping')->willReturn(false);

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot connect to Daktela API');
        $engine->testConnections();
    }

    public function testSavesTimestampAfterSuccessfulSync(): void
    {
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);
        $stateStore->expects(self::once())
            ->method('setLastSyncTime')
            ->with('contact', self::isInstanceOf(\DateTimeImmutable::class));

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->arrayToGenerator($contacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => new UpsertResult(Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-1']),
            )));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
            null,
            $stateStore,
        );

        $engine->syncContactsBatch();
    }

    public function testDoesNotSaveTimestampWhenRecordsFail(): void
    {
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);
        $stateStore->expects(self::never())->method('setLastSyncTime');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->arrayToGenerator($contacts));
        $ccAdapter->method('upsertContact')->willThrowException(new \RuntimeException('API failure'));

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
            null,
            $stateStore,
        );

        $engine->syncContactsBatch();
    }

    public function testResetStateIsNoOpWithoutStateStore(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $engine = new SyncEngine(
            $ccAdapter,
            $crmAdapter,
            $this->createConfig(),
            new NullLogger(),
        );

        // Should not throw
        $engine->resetState();
        $engine->resetState('contact');
        $this->addToAssertionCount(1);
    }

    public function testAnAbortedForcedFullSyncDoesNotSkipTheNextIncrementalWindow(): void
    {
        // The offset a forced (since = null) drain reached indexes the full
        // history. fullSync() clears the forced flag in a finally but the offset
        // outlives it, so the next ordinary run issues
        // iterateContacts(since = watermark, offset = 6) against a 3-row window,
        // gets nothing, reports 0 total / 0 failed / exhausted, and advances the
        // watermark over all three. Silent, permanent, through a documented API.
        $history = [];
        for ($i = 1; $i <= 10; $i++) {
            $history[] = Contact::fromArray(['id' => 'h' . $i, 'full_name' => 'H' . $i, 'email' => 'h' . $i . '@t.com']);
        }
        $window = [
            Contact::fromArray(['id' => 'w1', 'full_name' => 'W1', 'email' => 'w1@t.com']),
            Contact::fromArray(['id' => 'w2', 'full_name' => 'W2', 'email' => 'w2@t.com']),
            Contact::fromArray(['id' => 'w3', 'full_name' => 'W3', 'email' => 'w3@t.com']),
        ];

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('iterateContacts')->willReturnCallback(
            function (?\DateTimeImmutable $since, int $offset) use ($history, $window): \Generator {
                if ($since === null) {
                    // The CRM falls over six records into the forced drain.
                    if ($offset >= 6) {
                        throw new \RuntimeException('CRM 503');
                    }

                    yield from array_slice($history, $offset);

                    return;
                }

                yield from array_slice($window, $offset);
            },
        );

        $written = [];
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('upsertContact')->willReturnCallback(
            function (string $lookup, Contact $contact) use (&$written) {
                $written[] = (string) $contact->get('email');

                return new UpsertResult(Contact::fromArray(array_merge($contact->toArray(), ['id' => 'cc-1'])));
            },
        );

        $store = new \Daktela\CrmSync\State\FileSyncStateStore(
            tempnam(sys_get_temp_dir(), 'offsetleak_') . '.json',
        );
        $store->setLastSyncTime('contact', new \DateTimeImmutable('2026-01-01 00:00:00'));

        // Contacts ONLY. With a later entity enabled, that step's own
        // resetOffsets() preamble would incidentally clear the leak — so the
        // reachable shape is an abort in the last step that runs, or a config
        // like this one.
        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 2,
            entities: ['contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml')],
            mappings: ['contact' => new MappingCollection('contact', 'email', [
                new FieldMapping('title', 'full_name'),
                new FieldMapping('email', 'email'),
            ])],
        );

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $config, new NullLogger(), null, $store);

        $forced = $engine->fullSync(forceFullSync: true);
        self::assertTrue($forced->hasStepFailures(), 'the forced drain aborted');
        $written = [];

        $engine->syncContactsBatch();

        self::assertSame(['w1@t.com', 'w2@t.com', 'w3@t.com'], $written, 'the incremental window must not be skipped');
    }

    public function testResetStateWarnsThatExportsWillReSeedRatherThanRePushHistory(): void
    {
        // Clearing the watermark makes the next run look like a first run, and a
        // first run of an export with initial_sync: now seeds to now and pushes
        // nothing. Reset alone therefore does NOT re-push history — which the
        // operator has to be told, because the run afterwards reports "0 total,
        // 0 failed" and looks like a success.
        $logger = new \Daktela\CrmSync\Tests\Support\CapturingLogger();
        $stateStore = $this->createMock(SyncStateStoreInterface::class);

        $engine = new SyncEngine(
            $this->createMock(ContactCentreAdapterInterface::class),
            $this->createMock(CrmAdapterInterface::class),
            $this->createConfig(),
            $logger,
            null,
            $stateStore,
        );

        $engine->resetState();

        $warnings = $logger->messagesAt('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('activity', $warnings[0]);
        self::assertStringContainsString('forceFullSync', $warnings[0], 'the warning must name the working recovery');
    }

    public function testResetStateOfAnImportEntityDoesNotWarn(): void
    {
        // Imports read from the CRM with no seed rail, so a reset does exactly
        // what it says for them. Warning anyway would train operators to ignore it.
        $logger = new \Daktela\CrmSync\Tests\Support\CapturingLogger();

        $engine = new SyncEngine(
            $this->createMock(ContactCentreAdapterInterface::class),
            $this->createMock(CrmAdapterInterface::class),
            $this->createConfig(),
            $logger,
            null,
            $this->createMock(SyncStateStoreInterface::class),
        );

        $engine->resetState('contact');

        self::assertSame([], $logger->messagesAt('warning'));
    }

    /**
     * Regression: no state store means no watermark, so `initial_sync: now`
     * cannot seed and EVERY run pushes the full contact-centre history to the
     * CRM — not just the first. That must not happen by omission; a back-export
     * has to be asked for.
     */
    public function testActivityExportRefusesToRunWithoutAStateStore(): void
    {
        $engine = new SyncEngine(
            $this->createMock(ContactCentreAdapterInterface::class),
            $this->createMock(CrmAdapterInterface::class),
            $this->createConfig(),
            new NullLogger(),
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/no SyncStateStore is configured/');
        $engine->syncActivitiesBatch([ActivityType::Call]);
    }

    /**
     * `initial_sync: everything` asked for the history, so it is allowed without
     * a state store — refusing it rejected a shape that is provably safe on a
     * CRM whose upsertActivity() can find the record. But every later run
     * repeats the push, so the operator has to be told.
     */
    public function testEverythingWithoutAStateStoreIsAllowedButWarns(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturnCallback(fn () => $this->arrayToGenerator([]));

        $logger = new \Daktela\CrmSync\Tests\Support\CapturingLogger();
        $engine = new SyncEngine(
            $ccAdapter,
            $this->createMock(CrmAdapterInterface::class),
            $this->createConfig(initialSync: 'everything'),
            $logger,
        );

        $engine->syncActivitiesBatch([ActivityType::Call]);

        $warnings = $logger->messagesAt('warning');
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('EVERY run re-reads', implode(' ', $warnings));
    }

    /**
     * Regression: forceFullSync IS the deliberate history push the seed guard
     * exists to preserve, and the guard's own message names it as a remedy. An
     * earlier version ran the check before setForceFullSync() and never read the
     * flag, so the documented escape hatch was unreachable.
     */
    public function testForceFullSyncIsNotRefusedWithoutAStateStore(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturnCallback(fn () => $this->arrayToGenerator([]));

        $engine = new SyncEngine(
            $ccAdapter,
            $this->createMock(CrmAdapterInterface::class),
            $this->createConfig(),
            new NullLogger(),
        );

        $results = $engine->fullSync([ActivityType::Call], forceFullSync: true);

        self::assertFalse($results->hasStepFailures(), 'a forced back-export must not be blocked by the seed guard');
    }

    /**
     * Regression: a misconfigured activity export must not abort the whole run.
     * The guard used to throw out of fullSync() before FullSyncResult existed,
     * so contacts and accounts never ran and a scheduler checking
     * hasStepFailures() got an uncaught fatal instead.
     */
    public function testAMissingStateStoreFailsOnlyTheActivityStep(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('iterateContacts')->willReturnCallback(fn () => $this->arrayToGenerator([]));
        $crmAdapter->method('iterateAccounts')->willReturnCallback(fn () => $this->arrayToGenerator([]));

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $this->createConfig(), new NullLogger());

        $results = $engine->fullSync([ActivityType::Call]);

        self::assertTrue($results->hasStepFailures());
        self::assertArrayHasKey('activity', $results->stepFailures);
        self::assertStringContainsString('SyncStateStore', $results->stepFailures['activity']);
        self::assertNotNull($results->contact, 'contacts must still run');
    }

    /**
     * Regression: the guard used to require `enabled`, while the seeding rail it
     * protects does not — leaving a disabled entity's direct syncActivitiesBatch()
     * call free to push full history on every run.
     */
    public function testADisabledActivityEntityIsStillGuardedOnDirectCall(): void
    {
        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 't',
            database: 'd',
            batchSize: 100,
            entities: ['activity' => new EntitySyncConfig(false, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call])],
            mappings: [],
        );

        $engine = new SyncEngine(
            $this->createMock(ContactCentreAdapterInterface::class),
            $this->createMock(CrmAdapterInterface::class),
            $config,
            new NullLogger(),
        );

        $this->expectException(ConfigurationException::class);
        $engine->syncActivitiesBatch([ActivityType::Call]);
    }

    /**
     * A mapping that cannot dedupe must hold the watermark, not advance past the
     * records it refused.
     *
     * This is the case every fixture missed: with ONE activity a refusal is an
     * all-failed step and saveState() withholds the watermark, which looks safe.
     * With a MIXED batch — one type carrying the lookup value, another not — it
     * is a PARTIAL failure, and saveState() advances on partial failure. The
     * refused records then sit outside every future incremental window, and
     * activities have no `edited` field to bring them back: only a forced full
     * sync recovers them.
     *
     * So the fault aborts the step instead of failing records one at a time.
     */
    public function testAMappingThatCannotDedupeHoldsTheWatermarkInAMixedBatch(): void
    {
        $before = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $stateFile = sys_get_temp_dir() . '/wm-' . bin2hex(random_bytes(6)) . '.json';
        $store = new \Daktela\CrmSync\State\FileSyncStateStore($stateFile);
        $store->setLastSyncTime('activity', $before);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturnCallback(
            fn (ActivityType $t) => $this->arrayToGenerator([
                Activity::fromArray(['id' => $t->value . '-1', 'name' => $t->value . '-1', 'activity_type' => $t->value, 'title' => 'T']),
            ]),
        );

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-1']));

        // `external_id` is written for calls only; emails map to subject alone.
        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 't',
            database: 'd',
            batchSize: 100,
            entities: ['activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'a.yaml', [ActivityType::Call, ActivityType::Email])],
            mappings: ['activity' => new MappingCollection('activity', 'external_id', [
                new FieldMapping('title', 'subject'),
            ], [
                'call' => [new FieldMapping('name', 'external_id')],
            ])],
        );

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $config, new NullLogger(), null, $store);

        try {
            $engine->syncActivitiesBatch([ActivityType::Call, ActivityType::Email]);
            self::fail('a mapping that cannot dedupe must abort the step');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('cannot dedupe', $e->getMessage());
        }

        self::assertEquals(
            $before,
            $store->getLastSyncTime('activity'),
            'the watermark must not move past records the export refused',
        );

        @unlink($stateFile);
    }

    /**
     * A config with no `activity` entity at all must not reach the guard's
     * property read. Removing the null check turns this into
     * "Attempt to read property on null" — reachable from a programmatic
     * SyncConfiguration, which the guard's own docblock contemplates.
     */
    public function testAConfigWithNoActivityEntityDoesNotTripTheSeedGuard(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturnCallback(fn () => $this->arrayToGenerator([]));

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 't',
            database: 'd',
            batchSize: 100,
            entities: [],
            mappings: ['activity' => new MappingCollection('activity', 'external_id', [
                new FieldMapping('name', 'external_id'),
            ])],
        );

        $engine = new SyncEngine($ccAdapter, $this->createMock(CrmAdapterInterface::class), $config, new NullLogger());

        self::assertSame(0, $engine->syncActivitiesBatch([ActivityType::Call])->getTotalCount());
    }

    private function createConfig(int $batchSize = 100, string $initialSync = 'now'): SyncConfiguration
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
            batchSize: $batchSize,
            entities: [
                'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
                'account' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'accounts.yaml'),
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call], initialSync: $initialSync),
            ],
            mappings: [
                'contact' => $contactMapping,
                'account' => $accountMapping,
                'activity' => $activityMapping,
            ],
        );
    }

    private function createConfigWithRelations(): SyncConfiguration
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
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call],
                    // Orchestration fixture: it wants activities to export on the first run,
                    // which is a deliberate history push — stated in the config rather than
                    // left to a default, so the intent is visible at the fixture.
                    initialSync: 'everything'),
            ],
            mappings: [
                'contact' => $contactMapping,
                'account' => $accountMapping,
                'activity' => $activityMapping,
            ],
        );
    }

    private function createConfigWithDisabledAccount(): SyncConfiguration
    {
        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
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
                'account' => new EntitySyncConfig(false, SyncDirection::CrmToCc, 'accounts.yaml'),
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call],
                    // Orchestration fixture: it wants activities to export on the first run,
                    // which is a deliberate history push — stated in the config rather than
                    // left to a default, so the intent is visible at the fixture.
                    initialSync: 'everything'),
            ],
            mappings: [
                'contact' => $contactMapping,
                'activity' => $activityMapping,
            ],
        );
    }

    /**
     * @template T
     * @param T[] $items
     * @return \Generator<int, T>
     */
    private function arrayToGenerator(array $items): \Generator
    {
        yield from $items;
    }
}

