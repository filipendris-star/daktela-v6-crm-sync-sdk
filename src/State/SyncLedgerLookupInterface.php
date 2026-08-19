<?php

declare(strict_types=1);

namespace Daktela\CrmSync\State;

/**
 * Optional capability for a ledger that can hand back the CRM id it recorded.
 *
 * Required for correct webhook handling of multi-event records: one call emits
 * several events (call_create → call_answer → call_close), and every event after
 * the first must UPDATE the CRM record the first one created. The CRMs a ledger
 * exists for cannot search activities server-side, so the adapter's
 * find-then-upsert cannot locate that record — only the ledger knows its id.
 *
 * A ledger that does not implement this makes the webhook path fall back to
 * skipping events for records it has already exported: one CRM record per
 * activity (never duplicates), but frozen at the first event's payload.
 */
interface SyncLedgerLookupInterface extends SyncLedgerInterface
{
    /** The CRM id recorded for $ccId, or null if unknown / not yet exported. */
    public function findCrmId(string $entityType, string $ccId): ?string;
}
