<?php

declare(strict_types=1);

namespace Daktela\CrmSync\State;

/**
 * Opt-in capability for state stores that can persist an opaque resume cursor
 * next to the incremental watermark. Only cursor-paginated CRM adapters produce
 * one — see {@see \Daktela\CrmSync\Adapter\SupportsCursorPaginationInterface}.
 *
 * Deliberately kept OFF {@see SyncStateStoreInterface}: that interface is the
 * documented injection point for a host's own store (a DB- or Redis-backed one is
 * the natural production choice), so a new required method there is a boot-time
 * fatal for every existing implementation, not a degraded feature.
 *
 * A store that does NOT implement this degrades gracefully. The engine drives a
 * complete drain within a single run from its in-memory cursor; what is lost is
 * resuming an INTERRUPTED drain across runs, so the next run restarts that
 * entity's drain from the watermark. Records are re-read, never skipped.
 */
interface SupportsCursorStateInterface
{
    /**
     * Opaque resume cursor for cursor-paginated adapters. Returns null when no
     * partial drain is in progress (so the next run starts fresh from the time).
     */
    public function getCursor(string $key): ?string;

    /** Persist (or clear, when $cursor is null) the resume cursor for $key. */
    public function setCursor(string $key, ?string $cursor): void;
}
