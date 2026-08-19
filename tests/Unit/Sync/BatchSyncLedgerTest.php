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

    private function activity(string $id): Activity
    {
        $activity = Activity::fromArray(['id' => $id, 'name' => $id, 'title' => 'Call ' . $id]);
        $activity->setActivityType(ActivityType::Call);

        return $activity;
    }

    private function batchSync(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        ?SyncLedgerInterface $ledger,
    ): BatchSync {
        $activityMapping = new MappingCollection('activity', 'name', [
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
