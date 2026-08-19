<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

/**
 * One page of records from a cursor-paginated adapter, plus the token to fetch
 * the next page. The drain ends when `nextCursor` is null (or a page is empty) —
 * a short page alone does NOT signal the end, since filtered searches can return
 * fewer rows than the limit mid-drain. Adapters just hand back whatever the
 * API's "next" token was, and null on the last page.
 *
 * @template T
 */
final readonly class CursorPage
{
    /**
     * @param array<int, T> $records this page's records (already mapped to entities)
     * @param string|null $nextCursor token to resume from, or null if the API has no more
     */
    public function __construct(
        public array $records,
        public ?string $nextCursor,
    ) {
    }
}
