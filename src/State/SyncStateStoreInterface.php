<?php

declare(strict_types=1);

namespace Daktela\CrmSync\State;

/**
 * Persistent incremental-sync state: the per-entity watermark that turns a full
 * sync into an incremental one. This is the extension point for a host's own
 * store — implement it against a DB, Redis, or anything else and pass it to the
 * engine (see docs/05). Four methods, and it stays that way: methods are only
 * ever ADDED here in a major release, and the SDK's own newer needs go into
 * opt-in capability interfaces alongside it rather than in here.
 *
 * Pagination position is NOT stored here. A drain's position is only meaningful
 * together with the moment that drain started, and persisting the two separately
 * let a resumed drain write a fresh watermark over records it never re-read. An
 * interrupted drain therefore restarts from the watermark on the next run.
 */
interface SyncStateStoreInterface
{
    public function getLastSyncTime(string $entityType): ?\DateTimeImmutable;

    public function setLastSyncTime(string $entityType, \DateTimeImmutable $time): void;

    public function clear(string $entityType): void;

    public function clearAll(): void;
}
