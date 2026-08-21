<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

/**
 * Opt-in capability for Contact Centre adapters that can enumerate first-class
 * entity rows, which the cc_to_crm direction of `custom_entities` needs to read
 * its export set.
 *
 * Deliberately kept OFF {@see ContactCentreAdapterInterface}: adding a required
 * method there is a boot-time fatal for any host that implements its own CC
 * adapter. An adapter that does not implement this simply cannot serve a
 * cc_to_crm custom entity — the export step fails with a clear configuration
 * error naming the adapter, and every other sync direction is unaffected.
 */
interface SupportsEntityIterationInterface
{
    /**
     * Internal page size of the iterate* generators. The export layer caps one
     * batch at this value — consuming past one page while write-backs shrink the
     * filtered set strands rows (the next page's skip is computed against the
     * original set). Implementations paging by a different size MUST redeclare
     * this constant to match: the export layer reads it from the adapter
     * instance at runtime, so a redeclared value is honored.
     */
    public const ITERATE_PAGE_SIZE = 100;

    /**
     * Enumerate CC records of a first-class entity type ("contact" or "account")
     * as raw rows (with `id` populated), for cc_to_crm custom entity export.
     *
     * CAUTION for consumers that mutate the filtered result set while iterating
     * (the export's write-back renames records out of the $filters match): the
     * implementation pages internally by a fixed page size, and its next-page
     * skip is computed against the ORIGINAL set — consuming past the first page
     * under mutation lands the skip past unread rows. The export layer therefore
     * caps one batch at ITERATE_PAGE_SIZE (read from the adapter instance).
     *
     * @param array<int, array{field: string, operator: string, value: mixed}> $filters
     *        additional API-level filters (e.g. an export filter excluding
     *        CRM-originated records)
     * @param string $sinceField record field the $since cut-off applies to
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterateEntity(
        string $entityType,
        ?\DateTimeImmutable $since = null,
        int $offset = 0,
        array $filters = [],
        string $sinceField = 'edited',
    ): \Generator;
}
