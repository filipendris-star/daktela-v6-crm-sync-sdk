<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Sync\CursorPage;

/**
 * The cursor-paginated form of {@see CrmAdapterInterface::iterateCustomEntity()},
 * for adapters that already implement {@see SupportsCursorPaginationInterface}.
 *
 * A custom entity is read FROM the CRM, so on a cursor-only API it needs the same
 * treatment as contacts and accounts: left on offsets, the drain either re-reads
 * or skips rows while the other two entities page correctly against the same API.
 *
 * SEPARATE from SupportsCursorPaginationInterface on purpose. Adding a third
 * required method there would stop every adapter already implementing it from
 * loading until it was updated — the exact break this release exists to stop
 * repeating, and one the SDK cannot verify from here because the adapters live in
 * another repository. Declaring it separately means an adapter adopts custom-entity
 * cursor paging when it is ready, and one that has no custom entities at all never
 * has to write a throwing stub.
 *
 * The engine feature-detects this with `instanceof`. An adapter that implements
 * SupportsCursorPaginationInterface but NOT this one keeps the offset path for
 * custom entities only.
 *
 * The two may be merged in 2.0, where a required method is allowed.
 */
interface SupportsCustomEntityCursorPaginationInterface
{
    /**
     * `$entityName` is the adapter-interpreted `source` from the entry's config,
     * exactly as iterateCustomEntity() receives it. Rows are returned RAW — flat
     * associative arrays, not entities — also matching iterateCustomEntity().
     *
     * The drain ends when, and only when, a page carries a null `nextCursor`;
     * see SupportsCursorPaginationInterface for the full contract.
     *
     * @return CursorPage<array<string, mixed>>
     */
    public function fetchCustomEntityPage(
        string $entityName,
        ?\DateTimeImmutable $since,
        ?string $cursor,
        int $limit,
    ): CursorPage;
}
