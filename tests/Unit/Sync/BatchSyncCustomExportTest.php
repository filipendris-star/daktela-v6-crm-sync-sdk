<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsCustomEntityWriteInterface;
use Daktela\CrmSync\Config\CustomEntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\State\SyncStateStoreInterface;
use Daktela\CrmSync\Sync\BatchSync;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BatchSyncCustomExportTest extends TestCase
{
    /** CC contact rows the fake CC adapter will yield */
    private const ROWS = [
        ['id' => 'contact_abc', 'title' => '+420777111222', 'customFields' => ['number' => ['+420777111222']]],
    ];

    public function testExportCreatesCrmRecordAndAppliesWriteBack(): void
    {
        $ccAdapter = $this->ccAdapterYielding(self::ROWS);

        $renamed = null;
        $ccAdapter->expects(self::once())
            ->method('updateContact')
            ->willReturnCallback(function (string $id, Contact $contact) use (&$renamed) {
                $renamed = [$id, $contact->get('name')];

                return $contact;
            });

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->expects(self::once())
            ->method('createCustomEntity')
            ->with('persons', self::callback(fn (array $d) => $d['name'] === '+420777111222'))
            ->willReturn(['id' => 555, 'name' => '+420777111222']);

        $result = $this->runExport($ccAdapter, $crmAdapter, $this->entry(withWriteBack: true), since: new \DateTimeImmutable('-1 hour'));

        self::assertSame(1, $result->getCreatedCount());
        self::assertSame(0, $result->getFailedCount());
        self::assertSame(['contact_abc', 'pipedrive_person_555'], $renamed, 'write_back must stamp the prefixed CRM id');
    }

    public function testExportUpdatesWhenLookupMatchesAndSkipsWriteBack(): void
    {
        $ccAdapter = $this->ccAdapterYielding(self::ROWS);
        $ccAdapter->expects(self::never())->method('updateContact');

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(['id' => 99]);
        $crmAdapter->expects(self::never())->method('createCustomEntity');
        $crmAdapter->expects(self::once())
            ->method('updateCustomEntity')
            ->with('persons', '99', self::anything())
            ->willReturn(['id' => 99]);

        $result = $this->runExport($ccAdapter, $crmAdapter, $this->entry(withWriteBack: true), since: new \DateTimeImmutable('-1 hour'));

        self::assertSame(1, $result->getUpdatedCount());
    }

    public function testFirstRunSeedsCursorAndExportsNothing(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->expects(self::never())->method('iterateEntity');

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->expects(self::never())->method('createCustomEntity');

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);
        $stateStore->expects(self::once())
            ->method('setLastSyncTime')
            ->with('custom:contact_export', self::isInstanceOf(\DateTimeImmutable::class));

        $result = $this->runExport($ccAdapter, $crmAdapter, $this->entry(), stateStore: $stateStore);

        self::assertSame(0, $result->getTotalCount());
        self::assertTrue($result->isExhausted());
    }

    public function testExportFilterAndSinceFieldArePushedToCcQuery(): void
    {
        $entry = $this->entry();

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->expects(self::once())
            ->method('iterateEntity')
            ->with(
                'contact',
                self::isInstanceOf(\DateTimeImmutable::class),
                0,
                $entry->exportFilter,
                'edited',
            )
            ->willReturn($this->gen([]));

        $result = $this->runExport($ccAdapter, $this->writableCrmAdapter(), $entry, since: new \DateTimeImmutable('-1 hour'));

        self::assertSame(0, $result->getTotalCount());
    }

    public function testAdapterWithoutWriteSupportIsSkippedGracefully(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->expects(self::never())->method('iterateEntity');

        $crmAdapter = $this->createMock(CrmAdapterInterface::class); // no write interface

        $result = $this->runExport($ccAdapter, $crmAdapter, $this->entry(), since: new \DateTimeImmutable('-1 hour'));

        self::assertSame(0, $result->getTotalCount());
        self::assertTrue($result->isExhausted());
    }

    public function testFailedCrmCreateIsRecordedPerRecord(): void
    {
        $ccAdapter = $this->ccAdapterYielding(self::ROWS);

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willThrowException(new \RuntimeException('boom'));

        $result = $this->runExport($ccAdapter, $crmAdapter, $this->entry(), since: new \DateTimeImmutable('-1 hour'));

        self::assertSame(1, $result->getFailedCount());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function entry(bool $withWriteBack = false): CustomEntitySyncConfig
    {
        $writeBack = $withWriteBack ? [
            new FieldMapping('name', 'id', transformers: [
                ['name' => 'prefix', 'params' => ['value' => 'pipedrive_person_']],
            ]),
        ] : [];

        return new CustomEntitySyncConfig(
            name: 'contact_export',
            enabled: true,
            direction: SyncDirection::CcToCrm,
            source: 'contact',
            target: 'persons',
            mappingFile: 'mappings/contact_export.yaml',
            exportFilter: [
                ['field' => 'name', 'operator' => 'notlike', 'value' => 'pipedrive_person_%'],
            ],
            writeBack: $writeBack,
        );
    }

    private function mapping(): MappingCollection
    {
        return new MappingCollection('contact_export', 'name', [
            new FieldMapping('title', 'name'),
        ]);
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function ccAdapterYielding(array $rows): ContactCentreAdapterInterface&MockObject
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateEntity')->willReturn($this->gen($rows));

        return $ccAdapter;
    }

    private function writableCrmAdapter(): CrmAdapterInterface&SupportsCustomEntityWriteInterface&MockObject
    {
        return $this->createMockForIntersectionOfInterfaces([
            CrmAdapterInterface::class,
            SupportsCustomEntityWriteInterface::class,
        ]);
    }

    private function runExport(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        CustomEntitySyncConfig $entry,
        ?\DateTimeImmutable $since = null,
        ?SyncStateStoreInterface $stateStore = null,
    ): \Daktela\CrmSync\Sync\Result\SyncResult {
        if ($stateStore === null && $since !== null) {
            $stateStore = $this->createMock(SyncStateStoreInterface::class);
            $stateStore->method('getLastSyncTime')->willReturn($since);
        }

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [],
            mappings: [],
            customEntities: [$entry],
            customEntityMappings: ['contact_export' => $this->mapping()],
        );

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
            $stateStore,
        );

        return $batchSync->syncCustomEntity($entry, $this->mapping());
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return \Generator<int, array<string, mixed>>
     */
    private function gen(array $items): \Generator
    {
        yield from $items;
    }
}
