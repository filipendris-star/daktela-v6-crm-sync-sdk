<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Sync\CursorPage;

/**
 * Opt-in capability for CRMs whose list APIs paginate by an opaque/keyset cursor
 * (HubSpot `after`, K2 NextPageURL, Pipedrive v2 `cursor`) rather than a numeric
 * offset. The engine persists the returned `nextCursor` in the sync state between
 * runs and hands it back on the next page, so an interrupted drain resumes instead
 * of restarting. The cursor is cleared (drain complete) when a page returns fewer
 * records than the requested limit.
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
