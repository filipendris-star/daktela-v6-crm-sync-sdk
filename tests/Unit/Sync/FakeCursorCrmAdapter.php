<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsCursorPaginationInterface;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Sync\CursorPage;

/**
 * Minimal cursor-paginated CRM adapter for BatchSync tests: fetchContactsPage
 * returns a pre-seeded page keyed by the incoming cursor, and records which
 * cursors it was asked for.
 */
final class FakeCursorCrmAdapter implements CrmAdapterInterface, SupportsCursorPaginationInterface
{
    /** @var array<int, string|null> */
    public array $cursorsSeen = [];

    /** @param array<string, CursorPage<Contact>> $pagesByCursor keyed by incoming cursor ('' = first page / null) */
    public function __construct(private readonly array $pagesByCursor)
    {
    }

    public function fetchContactsPage(?\DateTimeImmutable $since, ?string $cursor, int $limit): CursorPage
    {
        $this->cursorsSeen[] = $cursor;

        return $this->pagesByCursor[$cursor ?? ''] ?? $this->pagesByCursor[(string) $cursor] ?? new CursorPage([], null);
    }

    public function fetchAccountsPage(?\DateTimeImmutable $since, ?string $cursor, int $limit): CursorPage
    {
        return new CursorPage([], null);
    }

    // ── unused CrmAdapterInterface surface ───────────────────────────────────
    public function findContact(string $id): ?Contact
    {
        return null;
    }

    public function findContactByLookup(string $field, string $value): ?Contact
    {
        return null;
    }

    public function iterateContacts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from [];
    }

    public function findAccount(string $id): ?Account
    {
        return null;
    }

    public function findAccountByLookup(string $field, string $value): ?Account
    {
        return null;
    }

    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from [];
    }

    public function searchContacts(string $query): \Generator
    {
        yield from [];
    }

    public function searchAccounts(string $query): \Generator
    {
        yield from [];
    }

    public function findActivity(string $id): ?Activity
    {
        return null;
    }

    public function findActivityByLookup(string $field, string $value): ?Activity
    {
        return null;
    }

    public function createActivity(Activity $activity): Activity
    {
        return $activity;
    }

    public function updateActivity(string $id, Activity $activity): Activity
    {
        return $activity;
    }

    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        return $activity;
    }

    public function ping(): bool
    {
        return true;
    }

    public function iterateCustomEntity(string $entityName, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from [];
    }

    public function findCustomEntity(string $entityName, string $id): ?array
    {
        return null;
    }

    public function findCustomEntityByLookup(string $entityName, string $field, string $value): ?array
    {
        return null;
    }
}
