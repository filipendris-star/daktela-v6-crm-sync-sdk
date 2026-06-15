<?php

declare(strict_types=1);

namespace Daktela\CrmSync\State;

interface SyncStateStoreInterface
{
    public function getLastSyncTime(string $entityType): ?\DateTimeImmutable;

    public function setLastSyncTime(string $entityType, \DateTimeImmutable $time): void;

    /**
     * Opaque resume cursor for cursor-paginated adapters. Returns null when no
     * partial drain is in progress (so the next run starts fresh from the time).
     */
    public function getCursor(string $key): ?string;

    /** Persist (or clear, when $cursor is null) the resume cursor for $key. */
    public function setCursor(string $key, ?string $cursor): void;

    public function clear(string $entityType): void;

    public function clearAll(): void;
}
