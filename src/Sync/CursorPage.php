<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

/**
 * One page of records from a cursor-paginated adapter, plus the token to fetch
 * the next page. Exhaustion is signalled by the caller comparing count($records)
 * against the requested limit (a short page = the end), so adapters don't have to
 * reason about it — they just hand back whatever the API's "next" token was.
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
