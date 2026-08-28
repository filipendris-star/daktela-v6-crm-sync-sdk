<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\State;

use Daktela\CrmSync\State\FileSyncStateStore;
use PHPUnit\Framework\TestCase;

/**
 * The watermark store.
 *
 * Everything incremental depends on this file being read and written correctly:
 * a watermark that reads back wrong moves the sync window, and a window that
 * moves the wrong way loses records silently. So the corruption paths matter as
 * much as the happy one — and every one of them must fail CLOSED, returning null
 * ("no watermark, sync everything") rather than a plausible wrong time.
 */
final class FileSyncStateStoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'state_');
        @unlink($base);
        $this->path = $base . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path) . '/nested-state');
    }

    public function testAWatermarkRoundTripsToTheSecond(): void
    {
        $store = new FileSyncStateStore($this->path);
        $time = new \DateTimeImmutable('2026-08-19 14:30:00', new \DateTimeZone('Europe/Prague'));

        $store->setLastSyncTime('contact', $time);

        self::assertEquals($time, $store->getLastSyncTime('contact'));
    }

    public function testTheStoredOffsetIsPreservedNotNormalised(): void
    {
        // Distinct from the round-trip above: comparing two DateTimeImmutables
        // compares the INSTANT, so a store that silently rewrote everything to UTC
        // would still pass that one. The watermark is formatted back into an API
        // filter, so the offset has to survive verbatim.
        $store = new FileSyncStateStore($this->path);
        $store->setLastSyncTime('contact', new \DateTimeImmutable('2025-06-15T10:30:00+05:30'));

        self::assertSame('+05:30', $store->getLastSyncTime('contact')?->format('P'));
    }

    public function testEntityWatermarksAreIndependent(): void
    {
        $store = new FileSyncStateStore($this->path);
        $store->setLastSyncTime('contact', new \DateTimeImmutable('2026-01-01 00:00:00'));
        $store->setLastSyncTime('account', new \DateTimeImmutable('2026-06-01 00:00:00'));

        self::assertSame('2026-01-01', $store->getLastSyncTime('contact')?->format('Y-m-d'));
        self::assertSame('2026-06-01', $store->getLastSyncTime('account')?->format('Y-m-d'));
    }

    public function testAnAbsentFileReadsAsNoWatermark(): void
    {
        self::assertNull((new FileSyncStateStore($this->path))->getLastSyncTime('contact'));
    }

    public function testAnUnknownEntityReadsAsNoWatermark(): void
    {
        $store = new FileSyncStateStore($this->path);
        $store->setLastSyncTime('contact', new \DateTimeImmutable());

        self::assertNull($store->getLastSyncTime('activity'));
    }

    /** @return iterable<string, array{string}> */
    public static function corruptFileProvider(): iterable
    {
        yield 'not JSON' => ['this is not json'];
        yield 'JSON but not an object' => ['"just a string"'];
        yield 'entity value is not a string' => ['{"contact": 12345}'];
        yield 'entity value is not a date' => ['{"contact": "yesterday"}'];
        yield 'wrong date format' => ['{"contact": "2026-08-19 14:30:00"}'];
    }

    /**
     * A corrupt store must read as "no watermark", never as a wrong one. Null
     * makes the next run re-read from the beginning — wasteful but lossless; a
     * wrong time silently skips everything before it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('corruptFileProvider')]
    public function testACorruptStoreReadsAsNoWatermark(string $contents): void
    {
        file_put_contents($this->path, $contents);

        self::assertNull((new FileSyncStateStore($this->path))->getLastSyncTime('contact'));
    }

    public function testClearRemovesOneEntityAndLeavesTheRest(): void
    {
        $store = new FileSyncStateStore($this->path);
        $store->setLastSyncTime('contact', new \DateTimeImmutable());
        $store->setLastSyncTime('account', new \DateTimeImmutable());

        $store->clear('contact');

        self::assertNull($store->getLastSyncTime('contact'));
        self::assertNotNull($store->getLastSyncTime('account'), 'clearing one entity must not clear another');
    }

    public function testClearAlsoDropsAStaleCursorRowFromAnOlderStore(): void
    {
        // Cursor state is no longer persisted. A store written by an earlier build
        // may still carry the row; clear() tidies it rather than leaving it to sit.
        file_put_contents($this->path, json_encode([
            'contact' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            '__cursors' => ['contact' => 'CURSOR_X'],
        ], JSON_THROW_ON_ERROR));

        (new FileSyncStateStore($this->path))->clear('contact');

        $raw = json_decode((string) file_get_contents($this->path), true);
        self::assertIsArray($raw);
        self::assertArrayNotHasKey('__cursors', $raw);
    }

    public function testClearAllEmptiesTheStore(): void
    {
        $store = new FileSyncStateStore($this->path);
        $store->setLastSyncTime('contact', new \DateTimeImmutable());
        $store->setLastSyncTime('account', new \DateTimeImmutable());

        $store->clearAll();

        self::assertNull($store->getLastSyncTime('contact'));
        self::assertNull($store->getLastSyncTime('account'));
    }

    public function testTheStoreDirectoryIsCreatedOnDemand(): void
    {
        // The documented advice is to point the store somewhere outside the release
        // directory, which usually does not exist on a first deploy.
        $nested = dirname($this->path) . '/nested-state/sync-state.json';
        $store = new FileSyncStateStore($nested);

        try {
            $store->setLastSyncTime('contact', new \DateTimeImmutable('2026-08-19 00:00:00'));

            self::assertSame('2026-08-19', $store->getLastSyncTime('contact')?->format('Y-m-d'));
        } finally {
            @unlink($nested);
        }
    }

}
