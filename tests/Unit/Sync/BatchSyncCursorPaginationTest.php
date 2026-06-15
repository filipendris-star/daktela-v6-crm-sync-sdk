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
        $this->stateFile = tempnam(sys_get_temp_dir(), 'cursor_state_') . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);
    }

    public function testFullPageStoresCursorThenShortPageClearsIt(): void
    {
        $store = new FileSyncStateStore($this->stateFile);

        // Page 1: full (batchSize=2) → cursor persisted, not exhausted.
        // Page 2: short (1 record) → exhausted, cursor cleared.
        $adapter = new FakeCursorCrmAdapter([
            null => new CursorPage([$this->contact('c1'), $this->contact('c2')], 'CURSOR_P2'),
            'CURSOR_P2' => new CursorPage([$this->contact('c3')], 'CURSOR_P3'),
        ]);

        $batch = $this->batchSync($adapter, $store, batchSize: 2);

        $r1 = $batch->syncContacts();
        self::assertFalse($r1->isExhausted(), 'full page is not exhausted');
        self::assertSame('CURSOR_P2', $store->getCursor('contact'), 'cursor persisted after full page');

        $r2 = $batch->syncContacts();
        self::assertTrue($r2->isExhausted(), 'short page is exhausted');
        self::assertNull($store->getCursor('contact'), 'cursor cleared at end of drain');

        self::assertSame([null, 'CURSOR_P2'], $adapter->cursorsSeen, 'page 2 resumed from the stored cursor');
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
