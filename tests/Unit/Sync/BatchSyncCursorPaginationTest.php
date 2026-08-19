<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsCursorPaginationInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
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

    public function testFullPageStoresCursorAndNullNextTokenEndsDrain(): void
    {
        $store = new FileSyncStateStore($this->stateFile);

        // Page 1: full (batchSize=2) → cursor persisted, not exhausted.
        // Page 2: short (1 record) BUT with a live next token → NOT exhausted;
        //         filtered searches (e.g. HubSpot) return short pages mid-drain.
        // Page 3: null next token → exhausted, cursor cleared.
        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], 'CURSOR_P2'),
            'CURSOR_P2' => new CursorPage([$this->contact('c3')], 'CURSOR_P3'),
            'CURSOR_P3' => new CursorPage([$this->contact('c4')], null),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);

        $r1 = $batch->syncContacts();
        self::assertFalse($r1->isExhausted(), 'full page is not exhausted');
        self::assertSame('CURSOR_P2', $store->getCursor('contact'), 'cursor persisted after full page');

        $r2 = $batch->syncContacts();
        self::assertFalse($r2->isExhausted(), 'short page with a live next token must NOT end the drain');
        self::assertSame('CURSOR_P3', $store->getCursor('contact'), 'live token persisted after short page');

        $r3 = $batch->syncContacts();
        self::assertTrue($r3->isExhausted(), 'null next token ends the drain');
        self::assertNull($store->getCursor('contact'), 'cursor cleared at end of drain');

        self::assertSame([null, 'CURSOR_P2', 'CURSOR_P3'], $adapter->cursorsSeen, 'each page resumed from the stored cursor');
    }

    public function testInterruptedRunResumesFromPersistedCursor(): void
    {
        // Run A processes page 1 and persists CURSOR_P2, then "crashes" (we stop).
        $store = new FileSyncStateStore($this->stateFile);
        $adapterA = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], 'CURSOR_P2'),
        ]);
        $this->batchSync($adapterA, $store, batchSize: 2)->syncContacts();
        self::assertSame('CURSOR_P2', $store->getCursor('contact'));

        // Run B: fresh BatchSync (in-memory cursors empty) must resume from disk.
        $adapterB = new FakeCursorCrmAdapter([
            'CURSOR_P2' => new CursorPage([$this->contact('c3')], null),
        ]);
        $rB = $this->batchSync($adapterB, $store, batchSize: 2)->syncContacts();

        self::assertSame(['CURSOR_P2'], $adapterB->cursorsSeen, 'resumed from the cursor a previous run left');
        self::assertTrue($rB->isExhausted());
        self::assertNull($store->getCursor('contact'));
    }

    public function testNullNextCursorIsTreatedAsExhaustedEvenOnFullPage(): void
    {
        $store = new FileSyncStateStore($this->stateFile);
        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], null),
        ]);

        $r = $this->batchSync($adapter, $store, batchSize: 2)->syncContacts();

        self::assertTrue($r->isExhausted(), 'null next cursor ends the drain');
        self::assertNull($store->getCursor('contact'));
    }


    public function testForceFullSyncIgnoresPersistedCursor(): void
    {
        // A previous run left a mid-drain token behind. A forced full re-sync
        // promises to start over — resuming from that stale token would skip
        // everything before it.
        $store = new FileSyncStateStore($this->stateFile);
        $store->setCursor('contact', 'STALE_MID_DRAIN');

        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1')], null),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);
        $batch->setForceFullSync(true);

        $result = $batch->syncContacts();

        self::assertSame([null], $adapter->cursorsSeen, 'forced re-sync must restart the drain, not resume the stale token');
        self::assertTrue($result->isExhausted());
        self::assertNull($store->getCursor('contact'));
    }


    public function testForceFullSyncDoesNotPersistMidDrainTokens(): void
    {
        // Forced drains run with since = null; their tokens are bound to that
        // query. Persisting one would make an interrupted forced run resume a
        // NORMAL (since-bound) run from a wrong-window token.
        $store = new FileSyncStateStore($this->stateFile);

        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], 'CURSOR_P2'),
            'CURSOR_P2' => new CursorPage([$this->contact('c3')], null),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);
        $batch->setForceFullSync(true);

        $r1 = $batch->syncContacts();
        self::assertFalse($r1->isExhausted());
        self::assertNull($store->getCursor('contact'), 'mid-drain token must not be persisted during a forced run');

        // In-run paging still works off the in-memory cursor.
        $r2 = $batch->syncContacts();
        self::assertTrue($r2->isExhausted());
        self::assertSame([null, 'CURSOR_P2'], $adapter->cursorsSeen);
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
        self::assertSame('CURSOR_P2', $store->getCursor('contact'), 'the live token must be kept');

        $r2 = $batch->syncContacts();
        self::assertSame(1, $r2->getTotalCount(), 'the record behind the token is read');
        self::assertTrue($r2->isExhausted());
    }

    public function testEndlessEmptyTokenedPagesAbortInsteadOfSpinning(): void
    {
        $store = new FileSyncStateStore($this->stateFile);

        // Faulty adapter: every page is empty but keeps handing back a token.
        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([], 'LOOP'),
            'LOOP' => new CursorPage([], 'LOOP'),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);

        $this->expectException(\Daktela\CrmSync\Exception\AdapterException::class);

        for ($i = 0; $i < 200; $i++) {
            $batch->syncContacts();
        }
    }

    private function batchSync(CrmAdapterInterface $crm, FileSyncStateStore $store, int $batchSize): BatchSync
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('upsertContact')->willReturnCallback(
            fn ($lookup, Contact $c) => new UpsertResult(Contact::fromArray(array_merge($c->toArray(), ['id' => 'cc-' . $c->get('name')])), created: true),
        );

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
