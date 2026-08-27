<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Integration\Fakes;

use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\Contact;

/**
 * In-memory CrmAdapter for integration tests. Stores preloaded contacts/accounts
 * and records iterator invocations so tests can assert incremental-sync filtering.
 */
class FakeCrmAdapter implements CrmAdapterInterface
{
    /** @var Contact[] */
    private array $contacts = [];

    /** @var Account[] */
    private array $accounts = [];

    /** @var Activity[] */
    public array $upsertedActivities = [];

    /** @var list<array{type: string, since: ?\DateTimeImmutable}> */
    public array $iterateCalls = [];

    /** @param Contact[] $contacts @param Account[] $accounts */
    public function __construct(array $contacts = [], array $accounts = [])
    {
        $this->contacts = $contacts;
        $this->accounts = $accounts;
    }

    public function findContact(string $id): ?Contact
    {
        foreach ($this->contacts as $c) {
            if ($c->getId() === $id) {
                return $c;
            }
        }

        return null;
    }

    public function findContactByLookup(string $field, string $value): ?Contact
    {
        foreach ($this->contacts as $c) {
            if ((string) $c->get($field) === $value) {
                return $c;
            }
        }

        return null;
    }

    public function iterateContacts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        $this->iterateCalls[] = ['type' => 'contact', 'since' => $since];

        $items = array_slice($this->contacts, $offset);
        foreach ($items as $c) {
            yield $c;
        }
    }

    public function findAccount(string $id): ?Account
    {
        foreach ($this->accounts as $a) {
            if ($a->getId() === $id) {
                return $a;
            }
        }

        return null;
    }

    public function findAccountByLookup(string $field, string $value): ?Account
    {
        foreach ($this->accounts as $a) {
            if ((string) $a->get($field) === $value) {
                return $a;
            }
        }

        return null;
    }

    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        $this->iterateCalls[] = ['type' => 'account', 'since' => $since];

        $items = array_slice($this->accounts, $offset);
        foreach ($items as $a) {
            yield $a;
        }
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
        $this->upsertedActivities[] = $activity;

        return $activity;
    }

    public function updateActivity(string $id, Activity $activity): Activity
    {
        $this->upsertedActivities[] = $activity;

        return $activity;
    }

    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $this->upsertedActivities[] = $activity;

        return $activity;
    }

    public function ping(): bool
    {
        return true;
    }

    /** @var array<string, list<array<string, mixed>>> Pre-loaded records keyed by entity name. */
    public array $customEntities = [];

    public function iterateCustomEntity(string $entityName, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        $this->iterateCalls[] = ['type' => "custom:{$entityName}", 'since' => $since];

        $items = array_slice($this->customEntities[$entityName] ?? [], $offset);
        foreach ($items as $record) {
            yield $record;
        }
    }

    public function findCustomEntity(string $entityName, string $id): ?array
    {
        foreach ($this->customEntities[$entityName] ?? [] as $record) {
            if (isset($record['id']) && (string) $record['id'] === $id) {
                return $record;
            }
        }

        return null;
    }

    public function findCustomEntityByLookup(string $entityName, string $field, string $value): ?array
    {
        foreach ($this->customEntities[$entityName] ?? [] as $record) {
            if (isset($record[$field]) && (string) $record[$field] === $value) {
                return $record;
            }
        }

        return null;
    }
}
