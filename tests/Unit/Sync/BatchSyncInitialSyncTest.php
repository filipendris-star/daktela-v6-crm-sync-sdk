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
use Daktela\CrmSync\State\SyncStateStoreInterface;
use Daktela\CrmSync\Sync\BatchSync;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BatchSyncInitialSyncTest extends TestCase
{
    public function testFirstRunSeedsCursorAndPushesNothingByDefault(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->expects(self::never())->method('iterateActivities');

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('upsertActivity');

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);
        $stateStore->expects(self::once())
            ->method('setLastSyncTime')
            ->with('activity', self::isInstanceOf(\DateTimeImmutable::class));

        $batchSync = $this->buildSync($ccAdapter, $crmAdapter, $stateStore, initialSync: 'now');
        $result = $batchSync->syncActivities([ActivityType::Call]);

        self::assertSame(0, $result->getTotalCount());
        self::assertTrue($result->isExhausted());
    }

    public function testFirstRunPushesHistoryWithInitialSyncEverything(): void
    {
        $activity = Activity::fromArray(['id' => 'call-1', 'name' => 'c1', 'title' => 'Old call']);
        $activity->setActivityType(ActivityType::Call);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->expects(self::once())
            ->method('iterateActivities')
            ->with(ActivityType::Call, null)
            ->willReturn($this->gen([$activity]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-1']));

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);

        $batchSync = $this->buildSync($ccAdapter, $crmAdapter, $stateStore, initialSync: 'everything');
        $result = $batchSync->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getTotalCount());
    }

    public function testForceFullSyncOverridesSeeding(): void
    {
        $activity = Activity::fromArray(['id' => 'call-1', 'name' => 'c1', 'title' => 'Old call']);
        $activity->setActivityType(ActivityType::Call);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$activity]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-1']));

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);
        $stateStore->expects(self::never())->method('setLastSyncTime');

        $batchSync = $this->buildSync($ccAdapter, $crmAdapter, $stateStore, initialSync: 'now');
        $batchSync->setForceFullSync(true);
        $result = $batchSync->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getTotalCount());
    }

    public function testExistingCursorBehavesAsBefore(): void
    {
        $since = new \DateTimeImmutable('-1 hour');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->expects(self::once())
            ->method('iterateActivities')
            ->with(ActivityType::Call, $since)
            ->willReturn($this->gen([]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn($since);
        $stateStore->expects(self::never())->method('setLastSyncTime');

        $batchSync = $this->buildSync($ccAdapter, $crmAdapter, $stateStore, initialSync: 'now');
        $result = $batchSync->syncActivities([ActivityType::Call]);

        self::assertSame(0, $result->getTotalCount());
    }

    public function testNoStateStoreSkipsSeedingAndSyncsEverything(): void
    {
        $activity = Activity::fromArray(['id' => 'call-1', 'name' => 'c1', 'title' => 'Old call']);
        $activity->setActivityType(ActivityType::Call);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$activity]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-1']));

        $batchSync = $this->buildSync($ccAdapter, $crmAdapter, stateStore: null, initialSync: 'now');
        $result = $batchSync->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getTotalCount());
    }

    private function buildSync(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        ?SyncStateStoreInterface $stateStore,
        string $initialSync,
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
                'activity' => new EntitySyncConfig(
                    enabled: true,
                    direction: SyncDirection::CcToCrm,
                    mappingFile: 'activities.yaml',
                    activityTypes: [ActivityType::Call],
                    initialSync: $initialSync,
                ),
            ],
            mappings: ['activity' => $activityMapping],
        );

        return new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
            $stateStore,
        );
    }

    /**
     * @param Activity[] $items
     * @return \Generator<int, Activity>
     */
    private function gen(array $items): \Generator
    {
        yield from $items;
    }
}
