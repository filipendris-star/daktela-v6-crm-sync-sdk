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

    /**
     * Record that $ccId was exported, storing the resulting CRM id (if any).
     *
     * Must behave as an UPSERT on (entityType, ccId): recording the same pair
     * again has to overwrite the stored CRM id, not fail. The webhook path
     * re-records when a record was deleted CRM-side and re-created, so a plain
     * INSERT against a unique key would throw there — after the replacement CRM
     * record already exists — leaving the ledger naming the dead one and adding
     * another copy on every subsequent event.
     *
     * A null $crmId is legal and means "exported, but the adapter named no
     * record". It still counts as exported for hasSynced().
     */
    public function recordSynced(string $entityType, string $ccId, ?string $crmId): void;
}
