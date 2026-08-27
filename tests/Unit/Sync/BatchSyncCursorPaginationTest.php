<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\BatchSync;
use Daktela\CrmSync\Sync\CursorPage;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BatchSyncCursorPaginationTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'cursor_state_');
        $this->stateFile = $base . '.json';
        @unlink($base); // tempnam creates the extension-less file; don't leak it
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    public function testADrainCompletesWithinOneRunFromTheInRunCursor(): void
    {
        $store = new FileSyncStateStore($this->stateFile);
        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], 'CURSOR_P2'),
            'CURSOR_P2' => new CursorPage([$this->contact('c3')], 'CURSOR_P3'),
            'CURSOR_P3' => new CursorPage([$this->contact('c4')], null),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);

        self::assertFalse($batch->syncContacts()->isExhausted(), 'full page is not exhausted');
        self::assertFalse($batch->syncContacts()->isExhausted(), 'short page with a live token must NOT end the drain');
        self::assertTrue($batch->syncContacts()->isExhausted(), 'null next token ends the drain');
        self::assertSame([null, 'CURSOR_P2', 'CURSOR_P3'], $adapter->cursorsSeen, 'each page resumed from the last token');
    }

    public function testAnInterruptedDrainRestartsRatherThanResuming(): void
    {
        // Cursor state is deliberately not persisted. A token means nothing
        // without the moment its drain started: a resumed run wrote a FRESH
        // watermark, which advanced the window past records it had never
        // re-read — including edits made to them after the interruption.
        //
        // So a new run starts the drain over. Pages already processed are re-read
        // and the adapter's upsert dedupes them — exactly how the offset path behaves.
        $store = new FileSyncStateStore($this->stateFile);

        $runA = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], 'CURSOR_P2'),
        ]);
        $this->batchSync($runA, $store, batchSize: 2)->syncContacts(); // interrupted here

        $runB = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1')], null),
        ]);
        $this->batchSync($runB, $store, batchSize: 2)->syncContacts();

        self::assertSame([null], $runB->cursorsSeen, 'the new run starts the drain from the beginning');
        self::assertSame(
            [],
            array_filter((array) json_decode((string) @file_get_contents($this->stateFile), true), static fn (string $k): bool => $k === '__cursors', ARRAY_FILTER_USE_KEY),
            'and no cursor is written to the state file at all',
        );
    }

    public function testForceFullSyncStartsItsOwnDrainRatherThanContinuingOne(): void
    {
        // The flag is part of the query — a forced drain runs with since = null —
        // so switching it must not carry a position across. Pinned because the
        // in-run cursor is now the only cursor there is.
        $store = new FileSyncStateStore($this->stateFile);
        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], 'HISTORY_P2'),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);
        $batch->setForceFullSync(true);
        $batch->syncContacts();          // forced drain reaches HISTORY_P2, then stops

        $batch->setForceFullSync(false); // what fullSync()'s finally does
        $batch->syncContacts();

        self::assertSame([null, null], $adapter->cursorsSeen, 'the incremental run started its own drain');
    }

    public function testNullNextCursorIsTreatedAsExhaustedEvenOnFullPage(): void
    {
        $store = new FileSyncStateStore($this->stateFile);
        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], null),
        ]);

        $r = $this->batchSync($adapter, $store, batchSize: 2)->syncContacts();

        self::assertTrue($r->isExhausted(), 'null next cursor ends the drain');
    }

    public function testEmptyPageWithLiveTokenDoesNotEndTheDrain(): void
    {
        // A scanned page whose rows were all filtered out server-side still
        // carries a live token. Ending the drain there would clear the cursor and
        // let the watermark advance over everything behind that token.
        $store = new FileSyncStateStore($this->stateFile);

        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([], 'CURSOR_P2'),
            'CURSOR_P2' => new CursorPage([$this->contact('c1')], null),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);

        $r1 = $batch->syncContacts();
        self::assertFalse($r1->isExhausted(), 'empty page with a live token must not end the drain');

        $r2 = $batch->syncContacts();
        self::assertSame(1, $r2->getTotalCount(), 'the record behind the token is read');
        self::assertTrue($r2->isExhausted());
    }

    public function testAdapterReturningTheTokenItWasGivenAbortsImmediately(): void
    {
        // A page handing back the very token it was asked for cannot advance —
        // the drain loop would request it forever. That must fail on the spot,
        // not after an arbitrary number of retries.
        $store = new FileSyncStateStore($this->stateFile);

        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1')], 'LOOP'),
            'LOOP' => new CursorPage([$this->contact('c2')], 'LOOP'),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);

        $batch->syncContacts(); // page 1: null -> 'LOOP', legitimate progress

        $this->expectException(\Daktela\CrmSync\Exception\AdapterException::class);
        $batch->syncContacts(); // page 2: 'LOOP' -> 'LOOP', no progress
    }

    public function testManyEmptyTokenedPagesAreLegitimateAndKeepDraining(): void
    {
        // A filtered search can return page after page of "nothing matched here"
        // with advancing tokens. That is declared legal by the interface, so the
        // drain must keep going — and a long-lived BatchSync must not accumulate
        // any state that eventually turns it into a fault.
        $store = new FileSyncStateStore($this->stateFile);

        $pages = [null => new CursorPage([], 'T1')];
        for ($i = 1; $i < 120; $i++) {
            $pages['T' . $i] = new CursorPage([], 'T' . ($i + 1));
        }
        $pages['T120'] = new CursorPage([$this->contact('c1')], null);

        $adapter = new FakeCursorCrmAdapter($pages);
        $batch = $this->batchSync($adapter, $store, batchSize: 2);

        $drains = 0;
        do {
            $result = $batch->syncContacts();
            self::assertLessThan(200, ++$drains, 'drain must terminate');
        } while (!$result->isExhausted());

        self::assertSame(121, $drains, 'every page was requested');
        self::assertSame(1, $result->getTotalCount(), 'the record behind the last token was read');
    }

    public function testAnEmptyNextCursorEndsTheDrainLikeNull(): void
    {
        // '' names no position. Read as a live token it cost one wasted page and
        // then aborted as "stalled" — blaming the adapter for a fault it did not
        // have, and disagreeing with the state store, which stores '' as absent.
        $store = new FileSyncStateStore($this->stateFile);
        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1')], ''),
        ]);

        $result = $this->batchSync($adapter, $store, batchSize: 2)->syncContacts();

        self::assertTrue($result->isExhausted());
        self::assertSame([null], $adapter->cursorsSeen, 'no second page was requested');
    }

    private function batchSync(CrmAdapterInterface $crm, \Daktela\CrmSync\State\SyncStateStoreInterface $store, int $batchSize): BatchSync
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('upsertContact')->willReturnCallback(
            fn ($lookup, Contact $c) => new UpsertResult(Contact::fromArray(array_merge($c->toArray(), ['id' => 'cc-' . $c->get('name')])), created: true),
        );

        return $this->batchSyncWith($ccAdapter, $crm, $store, $batchSize);
    }

    private function batchSyncWith(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crm,
        \Daktela\CrmSync\State\SyncStateStoreInterface $store,
        int $batchSize,
    ): BatchSync {
        $config = new SyncConfiguration(
            instanceUrl: 'https://t', accessToken: 't', database: 'd', batchSize: $batchSize,
            entities: ['contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'c.yaml')],
            mappings: ['contact' => new MappingCollection('contact', 'name', [
                new FieldMapping('name', 'name'),
                new FieldMapping('title', 'title'),
            ])],
        );

        return new BatchSync($ccAdapter, $crm, new FieldMapper(TransformerRegistry::withDefaults()), $config, new NullLogger(), $store);
    }

    private function contact(string $name): Contact
    {
        return new Contact($name, ['name' => $name, 'title' => $name]);
    }
}
