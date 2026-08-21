<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\State\SyncLedgerInterface;
use Daktela\CrmSync\Sync\BatchSync;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BatchSyncLedgerTest extends TestCase
{
    public function testAlreadySyncedActivityIsSkippedWithoutCrmCall(): void
    {
        $ledger = new InMemoryLedger(['activity:call-1' => 'crm-1']);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activity('call-1'),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('createActivity');
        $crmAdapter->expects(self::never())->method('upsertActivity');

        $result = $this->batchSync($ccAdapter, $crmAdapter, $ledger)->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getSkippedCount());
        self::assertSame(SyncStatus::Skipped, $result->getRecords()[0]->status);
    }

    public function testNewActivityIsCreatedWithoutLookupAndRecorded(): void
    {
        $ledger = new InMemoryLedger();

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activity('call-2'),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        // With a ledger set, dedup is the ledger's job: create directly, never
        // run the adapter's find-then-upsert lookup.
        $crmAdapter->expects(self::once())
            ->method('createActivity')
            ->willReturn(Activity::fromArray(['id' => 'crm-77']));
        $crmAdapter->expects(self::never())->method('upsertActivity');

        $result = $this->batchSync($ccAdapter, $crmAdapter, $ledger)->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getCreatedCount());
        self::assertTrue($ledger->hasSynced('activity', 'call-2'), 'created activity must be recorded');
        self::assertSame('crm-77', $ledger->recorded['activity:call-2'], 'CRM id must be stored with the record');
    }

    public function testFailedActivityIsNotRecordedInLedger(): void
    {
        $ledger = new InMemoryLedger();

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activity('call-3'),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('createActivity')->willThrowException(new \RuntimeException('CRM down'));

        $result = $this->batchSync($ccAdapter, $crmAdapter, $ledger)->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getFailedCount());
        self::assertFalse(
            $ledger->hasSynced('activity', 'call-3'),
            'a failed export must stay out of the ledger so the next run retries it',
        );
    }

    public function testWithoutLedgerFallsBackToUpsert(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activity('call-4'),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::once())
            ->method('upsertActivity')
            ->willReturn(Activity::fromArray(['id' => 'crm-88']));
        $crmAdapter->expects(self::never())->method('createActivity');

        $result = $this->batchSync($ccAdapter, $crmAdapter, ledger: null)->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getTotalCount());
    }


    public function testIdLessActivityKeepsUpsertDedupWhenLedgerIsSet(): void
    {
        // The ledger can neither check nor record an activity without a CC id, so
        // create-without-lookup would re-create it on every run. It must fall back
        // to the adapter's upsert (lookup-then-write) instead.
        $ledger = new InMemoryLedger();

        $idLess = Activity::fromArray(['title' => 'No id call']);
        $idLess->setActivityType(ActivityType::Call);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$idLess]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('createActivity');
        $crmAdapter->expects(self::once())
            ->method('upsertActivity')
            ->willReturn(Activity::fromArray(['id' => 'crm-1']));

        $result = $this->batchSync($ccAdapter, $crmAdapter, $ledger)->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getTotalCount());
        self::assertSame([], $ledger->recorded, 'nothing can be recorded without a CC id');
    }

    private function activity(string $id): Activity
    {
        $activity = Activity::fromArray(['id' => $id, 'name' => $id, 'title' => 'Call ' . $id]);
        $activity->setActivityType(ActivityType::Call);

        return $activity;
    }

    public function testATypeWithNoApplicableRulesFailsInsteadOfCreatingABlankRecord(): void
    {
        // A mapping file that declares only `types:` (the loader tolerates an
        // absent `default:`) leaves the base rule set empty, so an activity type
        // missing from that map maps to []. Writing that payload creates a blank
        // CRM record, which the ledger then records as exported — permanently,
        // and never revisited.
        $ledger = new InMemoryLedger();

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activityOfType('sms-1', ActivityType::Sms),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('createActivity');
        $crmAdapter->expects(self::never())->method('upsertActivity');

        $typesOnly = new MappingCollection('activity', 'name', [], [
            'call' => [new FieldMapping('title', 'subject')],
        ]);

        $result = $this->batchSync($ccAdapter, $crmAdapter, $ledger, $typesOnly)
            ->syncActivities([ActivityType::Sms]);

        self::assertSame(1, $result->getFailedCount());
        self::assertStringContainsString('empty payload', (string) $result->getFailedRecords()[0]->errorMessage);
        self::assertFalse(
            $ledger->hasSynced('activity', 'sms-1'),
            'nothing was exported, so the ledger must stay clean and the record retryable',
        );
    }

    private function batchSync(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        ?SyncLedgerInterface $ledger,
        ?MappingCollection $mappingOverride = null,
    ): BatchSync {
        $activityMapping = $mappingOverride ?? new MappingCollection('activity', 'name', [
            new FieldMapping('name', 'external_id'),
            new FieldMapping('title', 'subject'),
        ]);

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call]),
            ],
            mappings: [
                'activity' => $activityMapping,
            ],
        );

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
        );
        $batchSync->setLedger($ledger);

        return $batchSync;
    }

    /** @param array<int, Activity> $items */
    private function gen(array $items): \Generator
    {
        yield from $items;
    }

    public function testMultiTypeBatchBoundaryReadsEveryActivityExactlyOnce(): void
    {
        // One shared offset across activity types fed every type's query, so when
        // an earlier type contributed rows to a batch, the next batch skipped the
        // remaining type's unread rows — silent loss at every batch boundary.
        $perType = [
            'call' => [$this->activity('call-1')],
            'email' => [
                $this->activityOfType('email-1', ActivityType::Email),
                $this->activityOfType('email-2', ActivityType::Email),
                $this->activityOfType('email-3', ActivityType::Email),
            ],
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturnCallback(
            function (ActivityType $type, ?\DateTimeImmutable $since = null, int $offset = 0) use ($perType): \Generator {
                yield from array_slice($perType[$type->value], $offset);
            },
        );

        $exported = [];
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturnCallback(
            function (string $lookupField, Activity $activity) use (&$exported): Activity {
                $exported[] = $activity->get('external_id');

                return Activity::fromArray(['id' => 'crm-' . count($exported)]);
            },
        );

        $batchSync = $this->batchSyncForTypes($ccAdapter, $crmAdapter, [ActivityType::Call, ActivityType::Email], batchSize: 2);

        $batches = 0;
        do {
            $result = $batchSync->syncActivities([ActivityType::Call, ActivityType::Email]);
            self::assertLessThan(10, ++$batches, 'drain must terminate');
        } while (!$result->isExhausted());

        sort($exported);
        self::assertSame(['call-1', 'email-1', 'email-2', 'email-3'], $exported, 'every activity of every type read exactly once');
    }

    public function testLedgerReadFailureAbortsTheRunWithoutCrmCall(): void
    {
        // A read failure must abort: failing just the record would let a mixed
        // batch advance the watermark past an activity that was never created —
        // permanent loss. Aborting keeps the watermark; the next run retries and
        // the ledger dedups whatever was already created.
        $ledger = $this->createMock(SyncLedgerInterface::class);
        $ledger->method('hasSynced')->willThrowException(new \RuntimeException('ledger db down'));

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activity('call-1'),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('createActivity');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ledger db down');

        $this->batchSync($ccAdapter, $crmAdapter, $ledger)->syncActivities([ActivityType::Call]);
    }

    public function testExportBatchIsCappedAtAdapterPageSize(): void
    {
        // iterateEntity pages internally by 100 with a skip computed against the
        // original set; consuming past the first page while write-backs shrink
        // the filtered set would strand unread rows. One batch must therefore
        // never consume more than 100 rows, whatever batch_size says.
        $rows = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = ['id' => 'c' . $i, 'title' => 'T' . $i];
        }

        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            \Daktela\CrmSync\Adapter\SupportsEntityIterationInterface::class,
        ]);
        $ccAdapter->method('iterateEntity')->willReturnCallback(
            function (string $source, ?\DateTimeImmutable $since, int $offset) use ($rows): \Generator {
                yield from array_slice($rows, $offset);
            },
        );

        $crmAdapter = $this->createMockForIntersectionOfInterfaces([
            CrmAdapterInterface::class,
            \Daktela\CrmSync\Adapter\SupportsCustomEntityWriteInterface::class,
        ]);
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willReturn(['id' => 1]);

        $entry = new \Daktela\CrmSync\Config\CustomEntitySyncConfig(
            name: 'contact_export',
            enabled: true,
            direction: SyncDirection::CcToCrm,
            source: 'contact',
            target: 'persons',
            mappingFile: 'mappings/contact_export.yaml',
        );
        $mapping = new MappingCollection('contact_export', 'name', [
            new FieldMapping('title', 'name'),
        ]);

        $stateStore = $this->createMock(\Daktela\CrmSync\State\SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(new \DateTimeImmutable('-1 hour'));

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 250,
            entities: [],
            mappings: [],
            customEntities: [$entry],
            customEntityMappings: ['contact_export' => $mapping],
        );

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
            $stateStore,
        );

        $r1 = $batchSync->syncCustomEntity($entry, $mapping);
        self::assertSame(100, $r1->getTotalCount(), 'batch capped at the adapter page size despite batch_size=250');
        self::assertFalse($r1->isExhausted());

        $r2 = $batchSync->syncCustomEntity($entry, $mapping);
        self::assertSame(50, $r2->getTotalCount());
        self::assertTrue($r2->isExhausted());
    }

    public function testLedgerWriteFailureFailsRecordButRunContinues(): void
    {
        $ledger = $this->createMock(SyncLedgerInterface::class);
        $ledger->method('hasSynced')->willReturn(false);
        $calls = 0;
        $ledger->method('recordSynced')->willReturnCallback(function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('ledger write refused');
            }
        });

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activity('call-1'),
            $this->activity('call-2'),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::exactly(2))
            ->method('createActivity')
            ->willReturn(Activity::fromArray(['id' => 'crm-x']));

        $result = $this->batchSync($ccAdapter, $crmAdapter, $ledger)->syncActivities([ActivityType::Call]);

        // call-1: created in CRM but unrecorded -> surfaced as Failed (dedup is
        // compromised); call-2: created and recorded normally.
        self::assertSame(1, $result->getFailedCount());
        self::assertSame(1, $result->getCreatedCount());
        self::assertStringContainsString('ledger write failed', (string) $result->getRecords()[0]->errorMessage);
    }

    private function activityOfType(string $id, ActivityType $type): Activity
    {
        $activity = Activity::fromArray(['id' => $id, 'name' => $id, 'title' => 'Act ' . $id]);
        $activity->setActivityType($type);

        return $activity;
    }

    /** @param ActivityType[] $types */
    private function batchSyncForTypes(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        array $types,
        int $batchSize,
    ): BatchSync {
        $activityMapping = new MappingCollection('activity', 'name', [
            new FieldMapping('name', 'external_id'),
            new FieldMapping('title', 'subject'),
        ]);

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: $batchSize,
            entities: [
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', $types),
            ],
            mappings: [
                'activity' => $activityMapping,
            ],
        );

        return new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
        );
    }
}

/**
 * Array-backed ledger double; `recorded` exposes what was stored for assertions.
 */
final class InMemoryLedger implements SyncLedgerInterface
{
    /** @param array<string, ?string> $recorded "entityType:ccId" => crmId */
    public function __construct(public array $recorded = [])
    {
    }

    public function hasSynced(string $entityType, string $ccId): bool
    {
        return array_key_exists($entityType . ':' . $ccId, $this->recorded);
    }

    public function recordSynced(string $entityType, string $ccId, ?string $crmId): void
    {
        $this->recorded[$entityType . ':' . $ccId] = $crmId;
    }
}
