<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Sync\CursorPage;

/**
 * Opt-in capability for CRMs whose list APIs paginate by an opaque/keyset cursor
 * (HubSpot `after`, K2 NextPageURL, Pipedrive v2 `cursor`) rather than a numeric
 * offset. The engine persists the returned `nextCursor` in the sync state between
 * runs and hands it back on the next page, so an interrupted drain resumes instead
 * of restarting. The drain is complete when — and only when — a page carries a
 * null `nextCursor`; neither a short page nor an empty one ends it, since
 * filtered searches (e.g. HubSpot) legitimately return fewer rows than the limit
 * (possibly none at all) while more pages remain. Return `nextCursor: null` on
 * the last page.
 *
 * That null is the ONLY thing that ends a drain. A page whose token differs from
 * the one it was handed is trusted, however many record-less pages precede it,
 * because "runaway adapter" and "large sparsely-matching set" are
 * indistinguishable from the sync layer's side. Handing back the very token you
 * were given is the one detectable fault and aborts the drain. So an adapter
 * whose has_more never turns false drains until the process is stopped: getting
 * the terminating null right is the adapter's responsibility, and bounding a
 * run's total work belongs to whatever schedules it (see docs/09).
 *
 * Applies to the crm_to_cc imports (contacts, accounts) — those are read from the
 * CRM. Activities flow cc_to_crm and are read from the Contact Centre adapter, so
 * they are not covered here. Adapters that do NOT implement this keep using the
 * integer-offset iterate* path.
 */
interface SupportsCursorPaginationInterface
{
    /** @return CursorPage<\Daktela\CrmSync\Entity\Contact> */
    public function fetchContactsPage(?\DateTimeImmutable $since, ?string $cursor, int $limit): CursorPage;

    /** @return CursorPage<\Daktela\CrmSync\Entity\Account> */
    public function fetchAccountsPage(?\DateTimeImmutable $since, ?string $cursor, int $limit): CursorPage;
}
