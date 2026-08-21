<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsCustomEntityWriteInterface;
use Daktela\CrmSync\Adapter\SupportsEntityIterationInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
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

    public function testExportUpdatesWhenLookupMatchesAndRetriesWriteBack(): void
    {
        // A record that matches the export filter yet already exists in the CRM
        // means an earlier run created it but its write-back failed (the rename
        // is what removes it from the filter). The update branch must therefore
        // retry the write-back, or the record re-processes forever.
        $ccAdapter = $this->ccAdapterYielding(self::ROWS);

        $renamed = null;
        $ccAdapter->expects(self::once())
            ->method('updateContact')
            ->willReturnCallback(function (string $id, Contact $contact) use (&$renamed) {
                $renamed = [$id, $contact->get('name')];

                return $contact;
            });

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(['id' => 99]);
        $crmAdapter->expects(self::never())->method('createCustomEntity');
        $crmAdapter->expects(self::once())
            ->method('updateCustomEntity')
            ->with('persons', '99', self::anything())
            ->willReturn(['id' => 99]);

        $result = $this->runExport($ccAdapter, $crmAdapter, $this->entry(withWriteBack: true), since: new \DateTimeImmutable('-1 hour'));

        self::assertSame(1, $result->getUpdatedCount());
        self::assertSame(['contact_abc', 'pipedrive_person_99'], $renamed, 'update branch must retry the write-back rename');
    }

    public function testFirstRunSeedsCursorAndExportsNothing(): void
    {
        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            SupportsEntityIterationInterface::class,
        ]);
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

        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            SupportsEntityIterationInterface::class,
        ]);
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

    public function testAdapterWithoutWriteSupportAbortsInsteadOfSkipping(): void
    {
        // Returning a clean empty result would let saveState() advance this
        // entity's watermark on every run, so once the adapter gains the
        // capability everything edited in the meantime is outside the window.
        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            SupportsEntityIterationInterface::class,
        ]);
        $ccAdapter->expects(self::never())->method('iterateEntity');

        $crmAdapter = $this->createMock(CrmAdapterInterface::class); // no write interface

        $this->expectException(\Daktela\CrmSync\Exception\NotSupportedException::class);

        $this->runExport($ccAdapter, $crmAdapter, $this->entry(), since: new \DateTimeImmutable('-1 hour'));
    }

    public function testCcAdapterWithoutEntityIterationAbortsInsteadOfSkipping(): void
    {
        // Reading the export set is an opt-in capability, so a host's own CC
        // adapter — written before it existed — must still load and run every
        // other direction. Only this step fails, and it fails loudly: a clean
        // empty result would advance the watermark past records it never read.
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class); // no iteration interface

        $this->expectException(\Daktela\CrmSync\Exception\NotSupportedException::class);
        $this->expectExceptionMessageMatches('/SupportsEntityIterationInterface/');

        $this->runExport($ccAdapter, $this->writableCrmAdapter(), $this->entry(), since: new \DateTimeImmutable('-1 hour'));
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


    public function testUnsupportedWriteBackSourceIsDetectedInASingleBatch(): void
    {
        // A source whose write-back target applyExportWriteBack() does not support:
        // it only warns, so nothing ever leaves the export_filter and the rows would
        // be re-exported on every run forever. Detected even though the set fits in
        // a single batch and the spin comparison can never arm.
        //
        // This is the fallback branch: no write-back wrote at all, so there is no
        // set change to verify. The case where write-backs DO write but the records
        // stay put is covered by
        // testWriteBackThatDoesNotShrinkTheFilteredSetIsDetectedInASingleBatch.
        $entry = new CustomEntitySyncConfig(
            name: 'contact_export',
            enabled: true,
            direction: SyncDirection::CcToCrm,
            source: 'ticket', // write_back supports contact/account only
            target: 'persons',
            mappingFile: 'mappings/contact_export.yaml',
            exportFilter: [['field' => 'name', 'operator' => 'notlike', 'value' => 'pipedrive_person_%']],
            writeBack: [
                new FieldMapping('name', 'id', transformers: [
                    ['name' => 'prefix', 'params' => ['value' => 'pipedrive_person_']],
                ]),
            ],
        );

        $ccAdapter = $this->ccAdapterYielding([
            ['id' => 'c1', 'title' => 'A'],
            ['id' => 'c2', 'title' => 'B'],
        ]);
        $ccAdapter->expects(self::never())->method('updateContact');

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willReturn(['id' => 1]);

        $batchSync = $this->exportBatchSync($ccAdapter, $crmAdapter, batchSize: 100);

        $this->expectException(\Daktela\CrmSync\Exception\ConfigurationException::class);

        $batchSync->syncCustomEntity($entry, $this->mapping());
    }


    public function testTransientFailureIsNotBlamedOnTheWriteBackConfig(): void
    {
        // Mixed batch: one record's CRM create fails transiently, the other
        // succeeds but the CRM returns no id so its write-back is never attempted.
        // No write-back was attempted at all, so nothing proves the config is
        // wrong — throwing here would wedge the slot (its watermark can never
        // advance) on a passing outage.
        $ccAdapter = $this->ccAdapterYielding([
            ['id' => 'c1', 'title' => 'A'],
            ['id' => 'c2', 'title' => 'B'],
        ]);
        $ccAdapter->expects(self::never())->method('updateContact');

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $calls = 0;
        $crmAdapter->method('createCustomEntity')->willReturnCallback(
            function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new \RuntimeException('CRM 500');
                }

                return []; // succeeded but returned no id -> write-back cannot run
            },
        );

        $batchSync = $this->exportBatchSync($ccAdapter, $crmAdapter, batchSize: 100);

        $result = $batchSync->syncCustomEntity($this->entry(withWriteBack: true), $this->mapping());

        self::assertSame(1, $result->getFailedCount());
        self::assertTrue($result->isExhausted());
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
    public function testWriteBackThatDoesNotShrinkTheFilteredSetIsDetectedInASingleBatch(): void
    {
        // The real misconfiguration: write_back reports success on a supported
        // source, so "did it depart?" is true for every row — yet the records never
        // leave export_filter, because the field it writes is not the field the
        // filter checks. Nothing in the batch's own bookkeeping can see that.
        //
        // The store here is faithful: the write-back mutates it and the query
        // reflects the mutation. A fake that answers the verification query without
        // applying the write-back would pass this test no matter what the code does.
        // export_filter selects records with no crm_id; write_back writes 'note'.
        $cc = new ExportFilterAwareCcAdapter(
            rows: [['name' => 'c1', 'title' => 'A'], ['name' => 'c2', 'title' => 'B']],
            inFilteredSet: static fn (array $r): bool => ($r['crm_id'] ?? null) === null,
        );

        $entry = $this->entryWritingBack('note');
        $batchSync = $this->exportBatchSync($cc, $this->stubCrmAdapter(), batchSize: 100);

        $this->expectException(\Daktela\CrmSync\Exception\ConfigurationException::class);
        $batchSync->syncCustomEntity($entry, $this->mapping());
    }

    public function testWriteBackFollowingTheDocumentedRenameConventionIsNotBlamed(): void
    {
        // The convention docs/02 prescribes: write_back rewrites the very field the
        // export_filter checks, so the record leaves the set by being renamed. A
        // verification keyed on the record's original id reports "gone" here whether
        // or not anything worked — which is why the check re-asks the export query
        // instead of identifying the record.
        // export_filter selects records not yet renamed; write_back rewrites `name`.
        $cc = new ExportFilterAwareCcAdapter(
            rows: [['name' => 'c1', 'title' => 'A'], ['name' => 'c2', 'title' => 'B']],
            inFilteredSet: static fn (array $r): bool => !str_starts_with((string) $r['name'], 'pipedrive_person_'),
        );

        $entry = $this->entryWritingBack('name', [['field' => 'name', 'operator' => 'notlike', 'value' => 'pipedrive_person_%']]);
        $batchSync = $this->exportBatchSync($cc, $this->stubCrmAdapter(), batchSize: 100);

        $result = $batchSync->syncCustomEntity($entry, $this->mapping());

        self::assertSame(2, $result->getTotalCount());
        self::assertSame(0, $result->getFailedCount());
        self::assertSame([], $cc->matchingRows(), 'both records left the filtered set');
    }

    public function testTransientFailureOnTheFirstRowIsNotBlamedOnTheConfig(): void
    {
        // The first row legitimately stays in the set because its CRM write failed.
        // Blaming the config would throw ConfigurationException, freeze the
        // watermark and wedge the slot on every later run — turning one 503 into a
        // permanent "fix your config".
        $cc = new ExportFilterAwareCcAdapter(
            rows: [['name' => 'c1', 'title' => 'A']],
            inFilteredSet: static fn (array $r): bool => ($r['crm_id'] ?? null) === null,
        );

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willThrowException(new \RuntimeException('CRM 503'));

        $batchSync = $this->exportBatchSync($cc, $crmAdapter, batchSize: 100);
        $result = $batchSync->syncCustomEntity($this->entryWritingBack('note'), $this->mapping());

        self::assertSame(1, $result->getFailedCount(), 'reported as a record failure, not a config error');
    }

    public function testAFailingVerificationQueryDoesNotBlameTheConfig(): void
    {
        // The verification is diagnostic. If the CC side cannot answer it, that is
        // not evidence of a misconfiguration.
        $cc = new ExportFilterAwareCcAdapter(
            rows: [['name' => 'c1', 'title' => 'A']],
            inFilteredSet: static fn (array $r): bool => ($r['crm_id'] ?? null) === null,
            failVerification: true,
        );

        $batchSync = $this->exportBatchSync($cc, $this->stubCrmAdapter(), batchSize: 100);
        $result = $batchSync->syncCustomEntity($this->entryWritingBack('note'), $this->mapping());

        self::assertSame(1, $result->getTotalCount(), 'the export itself still counts');
        self::assertSame(0, $result->getFailedCount());
    }

    private function stubCrmAdapter(): CrmAdapterInterface&SupportsCustomEntityWriteInterface&MockObject
    {
        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willReturn(['id' => 1]);

        return $crmAdapter;
    }

    /** @param list<array<string, mixed>> $exportFilter */
    private function entryWritingBack(string $ccField, array $exportFilter = []): CustomEntitySyncConfig
    {
        return new CustomEntitySyncConfig(
            name: 'contact_export',
            enabled: true,
            direction: SyncDirection::CcToCrm,
            source: 'contact',
            target: 'persons',
            mappingFile: 'mappings/contact_export.yaml',
            exportFilter: $exportFilter !== [] ? $exportFilter : [['field' => 'crm_id', 'operator' => 'isnull', 'value' => null]],
            writeBack: [
                new FieldMapping($ccField, 'id', transformers: [
                    ['name' => 'prefix', 'params' => ['value' => 'pipedrive_person_']],
                ]),
            ],
        );
    }

    private function ccAdapterYielding(array $rows): ContactCentreAdapterInterface&SupportsEntityIterationInterface&MockObject
    {
        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            SupportsEntityIterationInterface::class,
        ]);
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

    public function testExportOffsetAccountsForRowsThatLeaveTheFilteredSet(): void
    {
        // The export_filter is pushed into the CC query and a successful
        // write-back renames the record OUT of that filter, so the result set
        // shrinks underneath the pagination. Advancing the offset by the full
        // batch count skipped rows that slid down into the window — and once
        // lastSyncTime advanced they were lost for good.
        $rows = [
            ['id' => 'c1', 'title' => 'A'],
            ['id' => 'c2', 'title' => 'B'],
            ['id' => 'c3', 'title' => 'C'],
        ];

        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            SupportsEntityIterationInterface::class,
        ]);
        $ccAdapter->method('iterateEntity')->willReturnCallback(
            function (string $source, ?\DateTimeImmutable $since, int $offset) use (&$rows): \Generator {
                yield from array_slice($rows, $offset);
            },
        );
        // write-back rename = the record leaves the filtered set
        $ccAdapter->method('updateContact')->willReturnCallback(
            function (string $id, Contact $contact) use (&$rows): Contact {
                $rows = array_values(array_filter($rows, fn (array $r) => $r['id'] !== $id));

                return $contact;
            },
        );

        $exported = [];
        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willReturnCallback(
            function (string $target, array $data) use (&$exported): array {
                $exported[] = $data['name'];

                return ['id' => count($exported)];
            },
        );

        $batchSync = $this->exportBatchSync($ccAdapter, $crmAdapter, batchSize: 2);

        $batches = 0;
        do {
            $result = $batchSync->syncCustomEntity($this->entry(withWriteBack: true), $this->mapping());
            self::assertLessThan(10, ++$batches, 'export loop must terminate');
        } while (!$result->isExhausted());

        self::assertSame(['A', 'B', 'C'], $exported, 'every row must be exported exactly once');
        self::assertSame([], $rows, 'all rows left the filtered set');
    }

    public function testExportSpinGuardAbortsWhenWriteBackDoesNotShrinkTheSet(): void
    {
        // Degenerate config: write_back "succeeds" but writes fields the
        // export_filter does not check, so rows never leave the set. The guard
        // must ABORT (configuration error): skipping forward would step over rows
        // that genuinely departed mid-batch and slid down, and continuing would
        // re-serve the same batch forever. Aborting keeps the watermark.
        $rows = [
            ['id' => 'c1', 'title' => 'A'],
            ['id' => 'c2', 'title' => 'B'],
            ['id' => 'c3', 'title' => 'C'],
        ];

        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            SupportsEntityIterationInterface::class,
        ]);
        $ccAdapter->method('iterateEntity')->willReturnCallback(
            function (string $source, ?\DateTimeImmutable $since, int $offset) use (&$rows): \Generator {
                yield from array_slice($rows, $offset);
            },
        );
        // write-back runs fine but does NOT remove the row from the set
        $ccAdapter->method('updateContact')->willReturnArgument(1);

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willReturn(['id' => 1]);

        $batchSync = $this->exportBatchSync($ccAdapter, $crmAdapter, batchSize: 2);

        // Batch 1 processes and stores the guard; batch 2 re-serves the same
        // first row at the same offset -> configuration error.
        $r1 = $batchSync->syncCustomEntity($this->entry(withWriteBack: true), $this->mapping());
        self::assertFalse($r1->isExhausted());

        $this->expectException(\Daktela\CrmSync\Exception\ConfigurationException::class);
        $batchSync->syncCustomEntity($this->entry(withWriteBack: true), $this->mapping());
    }

    public function testSpinAbortDoesNotSkipRowsThatGenuinelyDeparted(): void
    {
        // Mixed batch: c1's write-back really removes it from the set, the others
        // only pretend. The old guard advanced offset by the full batch count on a
        // trip, silently skipping the rows that slid down into the window. The
        // abort must fire instead, with every row still accounted for: either
        // exported or still present in the filtered set — never vanished.
        $rows = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $i => $t) {
            $rows[] = ['id' => 'c' . ($i + 1), 'title' => $t];
        }

        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            SupportsEntityIterationInterface::class,
        ]);
        $ccAdapter->method('iterateEntity')->willReturnCallback(
            function (string $source, ?\DateTimeImmutable $since, int $offset) use (&$rows): \Generator {
                yield from array_slice($rows, $offset);
            },
        );
        // Only c1 genuinely departs; every other write-back is a no-op.
        $ccAdapter->method('updateContact')->willReturnCallback(
            function (string $id, Contact $contact) use (&$rows): Contact {
                if ($id === 'c1') {
                    $rows = array_values(array_filter($rows, fn (array $r) => $r['id'] !== 'c1'));
                }

                return $contact;
            },
        );

        $exported = [];
        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willReturnCallback(
            function (string $target, array $data) use (&$exported): array {
                $exported[] = $data['name'];

                return ['id' => count($exported)];
            },
        );

        $batchSync = $this->exportBatchSync($ccAdapter, $crmAdapter, batchSize: 2);

        $thrown = false;
        try {
            $batches = 0;
            do {
                $result = $batchSync->syncCustomEntity($this->entry(withWriteBack: true), $this->mapping());
                self::assertLessThan(10, ++$batches, 'drain must terminate');
            } while (!$result->isExhausted());
        } catch (\Daktela\CrmSync\Exception\ConfigurationException) {
            $thrown = true;
        }

        self::assertTrue($thrown, 'misconfigured write_back must abort the drain');
        // No silent loss: every row is either exported or still in the set.
        $accounted = array_unique(array_merge($exported, array_column($rows, 'title')));
        sort($accounted);
        self::assertSame(['A', 'B', 'C', 'D', 'E', 'F'], $accounted, 'no row may vanish unexported AND removed');
    }

    public function testExportLookupResolvesDottedCrmField(): void
    {
        // On export the lookup key addresses the mapped (CRM-side) payload, and
        // dotted fields are written nested — flat access returned null, silently
        // skipped the existence check and created a duplicate on every run.
        $mapping = new MappingCollection('contact_export', 'custom.ext_id', [
            new FieldMapping('title', 'name'),
            new FieldMapping('id', 'custom.ext_id'),
        ]);

        $ccAdapter = $this->ccAdapterYielding(self::ROWS);

        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->expects(self::once())
            ->method('findCustomEntityByLookup')
            ->with('persons', 'custom.ext_id', 'contact_abc')
            ->willReturn(['id' => 7]);
        $crmAdapter->expects(self::never())->method('createCustomEntity');
        $crmAdapter->expects(self::once())
            ->method('updateCustomEntity')
            ->with('persons', '7', self::anything())
            ->willReturn(['id' => 7]);

        $batchSync = $this->exportBatchSync($ccAdapter, $crmAdapter, batchSize: 100);
        $result = $batchSync->syncCustomEntity($this->entry(), $mapping);

        self::assertSame(1, $result->getUpdatedCount());
    }


    public function testCapPreventsStrandingUnderPageFaithfulMutation(): void
    {
        // The motivating property of EXPORT_BATCH_LIMIT: the adapter serves
        // internal pages of 100 whose skip is computed against the CURRENT set.
        // Without the cap, one 250-row batch would consume page 1 (100 rows, all
        // departing), then fetch page 2 at skip=100 against the 50-row shrunken
        // set -> empty -> "exhausted" -> rows 101-150 stranded forever. With the
        // cap, the generator is abandoned before page 2 is ever requested.
        $rows = [];
        for ($i = 1; $i <= 150; $i++) {
            $rows[] = ['id' => 'c' . $i, 'title' => 'T' . $i];
        }

        $ccAdapter = $this->createMockForIntersectionOfInterfaces([
            ContactCentreAdapterInterface::class,
            SupportsEntityIterationInterface::class,
        ]);
        $ccAdapter->method('iterateEntity')->willReturnCallback(
            function (string $source, ?\DateTimeImmutable $since, int $offset) use (&$rows): \Generator {
                // Page-faithful fake: pages of 100, each re-sliced against the
                // live set — exactly the real adapter's skip += pageSize walk.
                $cursor = $offset;
                while (true) {
                    $page = array_slice($rows, $cursor, SupportsEntityIterationInterface::ITERATE_PAGE_SIZE);
                    if ($page === []) {
                        return;
                    }
                    yield from $page;
                    $cursor += SupportsEntityIterationInterface::ITERATE_PAGE_SIZE;
                }
            },
        );
        // write-back rename = the record leaves the filtered set
        $ccAdapter->method('updateContact')->willReturnCallback(
            function (string $id, Contact $contact) use (&$rows): Contact {
                $rows = array_values(array_filter($rows, fn (array $r) => $r['id'] !== $id));

                return $contact;
            },
        );

        $exported = [];
        $crmAdapter = $this->writableCrmAdapter();
        $crmAdapter->method('findCustomEntityByLookup')->willReturn(null);
        $crmAdapter->method('createCustomEntity')->willReturnCallback(
            function (string $target, array $data) use (&$exported): array {
                $exported[] = $data['name'];

                return ['id' => count($exported)];
            },
        );

        $batchSync = $this->exportBatchSync($ccAdapter, $crmAdapter, batchSize: 250);

        $batches = 0;
        do {
            $result = $batchSync->syncCustomEntity($this->entry(withWriteBack: true), $this->mapping());
            self::assertLessThan(10, ++$batches, 'export loop must terminate');
        } while (!$result->isExhausted());

        self::assertCount(150, $exported, 'every row must be exported despite batch_size > page size');
        self::assertSame([], $rows, 'all rows left the filtered set');
    }

    private function exportBatchSync(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        int $batchSize,
    ): BatchSync {
        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(new \DateTimeImmutable('-1 hour'));

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: $batchSize,
            entities: [],
            mappings: [],
            customEntities: [$this->entry(withWriteBack: true)],
            customEntityMappings: ['contact_export' => $this->mapping()],
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
}

/**
 * CC adapter with a real store: `name` is the identity, `updateContact()` mutates
 * (including renaming when the write-back targets `name`), and `iterateEntity()`
 * honours the entry's export_filter against the current contents. Without that
 * fidelity a fixture can assert a state the CC side could never be in.
 */
final class ExportFilterAwareCcAdapter implements ContactCentreAdapterInterface, SupportsEntityIterationInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $store = [];

    /**
     * @param list<array<string, mixed>>       $rows
     * @param callable(array<string, mixed>): bool $inFilteredSet what export_filter selects
     */
    public function __construct(
        array $rows,
        private readonly \Closure $inFilteredSet,
        private readonly bool $failVerification = false,
    ) {
        foreach ($rows as $row) {
            $this->store[(string) $row['name']] = $row;
        }
    }

    /** @return list<string> names of rows still inside the filtered set */
    public function matchingRows(): array
    {
        $out = [];
        foreach ($this->store as $name => $row) {
            if (($this->inFilteredSet)($row)) {
                $out[] = (string) $name;
            }
        }

        return $out;
    }

    public function iterateEntity(
        string $entityType,
        ?\DateTimeImmutable $since = null,
        int $offset = 0,
        array $filters = [],
        string $sinceField = 'edited',
    ): \Generator {
        if ($this->failVerification && $this->queries > 0) {
            throw new \RuntimeException('CC /contacts returned 503');
        }
        $this->queries++;

        $matching = [];
        foreach ($this->matchingRows() as $name) {
            $row = $this->store[$name];
            $row['id'] = $name;
            $matching[] = $row;
        }

        yield from array_slice($matching, $offset);
    }

    private int $queries = 0;

    public function updateContact(string $id, Contact $contact): Contact
    {
        $existing = $this->store[$id] ?? [];
        $written = array_filter($contact->toArray(), static fn ($v) => $v !== null);

        // A write to `name` renames the record — its identity moves with it.
        $newName = isset($written['name']) ? (string) $written['name'] : $id;
        unset($this->store[$id]);
        $this->store[$newName] = array_merge($existing, $written, ['name' => $newName]);

        return Contact::fromArray($this->store[$newName]);
    }

    public function findContact(string $id): ?Contact
    {
        return isset($this->store[$id]) ? Contact::fromArray($this->store[$id]) : null;
    }

    public function findContactBy(array $criteria): ?Contact
    {
        return null;
    }

    public function createContact(Contact $contact): Contact
    {
        return $contact;
    }

    public function upsertContact(string $lookupField, Contact $contact): UpsertResult
    {
        return new UpsertResult($contact);
    }

    public function findAccount(string $id): ?Account
    {
        return null;
    }

    public function findAccountBy(array $criteria): ?Account
    {
        return null;
    }

    public function createAccount(Account $account): Account
    {
        return $account;
    }

    public function updateAccount(string $id, Account $account): Account
    {
        return $account;
    }

    public function upsertAccount(string $lookupField, Account $account): UpsertResult
    {
        return new UpsertResult($account);
    }

    public function findActivity(string $id, ActivityType $type): ?Activity
    {
        return null;
    }

    public function iterateActivities(ActivityType $type, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from [];
    }

    public function ping(): bool
    {
        return true;
    }
}
