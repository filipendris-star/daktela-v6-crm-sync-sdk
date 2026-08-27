<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

/**
 * One page of records from a cursor-paginated adapter, plus the token to fetch
 * the next page. The drain ends when — and only when — `nextCursor` is null: a
 * short page does NOT signal the end (filtered searches can return fewer rows
 * than the limit mid-drain), and neither does an empty one. Adapters just hand
 * back whatever the API's "next" token was, and null on the last page.
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
