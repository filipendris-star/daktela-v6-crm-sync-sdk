<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsDealLinkingInterface;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\BatchSync;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BatchSyncDealLinkingTest extends TestCase
{
    public function testLinkDealHookInvokedWhenConfiguredAndSupported(): void
    {
        $activity = Activity::fromArray(['id' => 'call-1', 'name' => 'c1', 'title' => 'Call 1']);
        $activity->setActivityType(ActivityType::Call);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$activity]));

        $crmAdapter = $this->createMockForIntersectionOfInterfaces([
            CrmAdapterInterface::class,
            SupportsDealLinkingInterface::class,
        ]);

        $linked = null;
        $crmAdapter->expects(self::once())
            ->method('linkActivityToDeal')
            ->with(self::isInstanceOf(Activity::class), 'latest_open')
            ->willReturnCallback(function (Activity $a) use (&$linked) {
                $a->set('deal_id', '777');
                $linked = $a;

                return $a;
            });

        $crmAdapter->method('upsertActivity')
            ->willReturnCallback(function (string $lookup, Activity $a) {
                self::assertSame('777', $a->get('deal_id'), 'upserted activity must carry the linked deal');

                return Activity::fromArray(['id' => 'crm-act-1']);
            });

        $result = $this->runActivitySync($ccAdapter, $crmAdapter, linkDeal: 'latest_open');

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(0, $result->getFailedCount());
        self::assertNotNull($linked);
    }

    public function testLinkDealIgnoredWhenAdapterDoesNotSupportIt(): void
    {
        $activity = Activity::fromArray(['id' => 'call-1', 'name' => 'c1', 'title' => 'Call 1']);
        $activity->setActivityType(ActivityType::Call);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$activity]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::once())
            ->method('upsertActivity')
            ->willReturn(Activity::fromArray(['id' => 'crm-act-1']));

        $result = $this->runActivitySync($ccAdapter, $crmAdapter, linkDeal: 'latest_open');

        self::assertSame(0, $result->getFailedCount());
    }

    public function testPerTypeMappingRulesApplyDuringActivitySync(): void
    {
        $activity = Activity::fromArray([
            'id' => 'call-1',
            'name' => 'c1',
            'title' => 'Call 1',
            'item_answered' => false,
        ]);
        $activity->setActivityType(ActivityType::Call);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$activity]));

        $captured = null;
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')
            ->willReturnCallback(function (string $lookup, Activity $a) use (&$captured) {
                $captured = $a;

                return Activity::fromArray(['id' => 'crm-act-1']);
            });

        $typeRules = [
            'call' => [
                new FieldMapping('item_answered', 'done', transformers: [
                    ['name' => 'value_map', 'params' => ['map' => ['false' => 0], 'default' => 1]],
                ]),
            ],
        ];

        $this->runActivitySync($ccAdapter, $crmAdapter, typeMappings: $typeRules);

        self::assertNotNull($captured);
        self::assertSame(0, $captured->get('done'), 'call type rule must derive done=0 from item_answered=false');
    }

    /**
     * @param array<string, FieldMapping[]> $typeMappings
     */
    private function runActivitySync(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        ?string $linkDeal = null,
        array $typeMappings = [],
    ): \Daktela\CrmSync\Sync\Result\SyncResult {
        $activityMapping = new MappingCollection('activity', 'name', [
            new FieldMapping('name', 'external_id'),
            new FieldMapping('title', 'subject'),
        ], $typeMappings);

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
                    linkDeal: $linkDeal,
                ),
            ],
            mappings: ['activity' => $activityMapping],
        );

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
        );

        return $batchSync->syncActivities([ActivityType::Call]);
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
