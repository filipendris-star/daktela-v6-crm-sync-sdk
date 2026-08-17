<?php

declare(strict_types=1);

namespace Daktela\CrmSync\State;

/**
 * Idempotency ledger for one-way exports the target CRM cannot dedupe
 * server-side — notably activities, which most CRMs have no way to search.
 *
 * The sync layer asks "have we already exported this CC record?" before
 * creating, and records the pair afterwards, so re-runs and full re-syncs never
 * duplicate.
 */
interface SyncLedgerInterface
{
    /** True if $ccId (a Contact Centre record id) has already been exported. */
    public function hasSynced(string $entityType, string $ccId): bool;

    /** Record that $ccId was exported, storing the resulting CRM id (if any). */
    public function recordSynced(string $entityType, string $ccId, ?string $crmId): void;
}
