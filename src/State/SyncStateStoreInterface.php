<?php

declare(strict_types=1);

namespace Daktela\CrmSync\State;

/**
 * Persistent incremental-sync state: the per-entity watermark that turns a full
 * sync into an incremental one. This is the extension point for a host's own
 * store — implement it against a DB, Redis, or anything else and pass it to the
 * engine (see docs/05). Methods are only ever ADDED here in a major release; the
 * SDK's own newer needs go into opt-in capability interfaces alongside it, e.g.
 * {@see SupportsCursorStateInterface} for cursor-paginated adapters.
 */
interface SyncStateStoreInterface
{
    public function getLastSyncTime(string $entityType): ?\DateTimeImmutable;

    public function setLastSyncTime(string $entityType, \DateTimeImmutable $time): void;

    public function clear(string $entityType): void;

    public function clearAll(): void;
}
