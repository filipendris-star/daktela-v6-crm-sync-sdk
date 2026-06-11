<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Integration\Fakes;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;

/**
 * In-memory ContactCentre adapter for integration tests. Stores upserted
 * contacts/accounts keyed by lookup field, and can be configured to throw
 * for specific lookup values to simulate API failures.
 */
final class FakeCcAdapter implements ContactCentreAdapterInterface
{
    /** @var array<string, Contact> */
    public array $contacts = [];

    /** @var array<string, Account> */
    public array $accounts = [];

    /** @var array<string, true> lookup values that should fail contact upsert */
    private array $failContactLookups = [];

    /** @var array<string, true> lookup values that should fail account upsert */
    private array $failAccountLookups = [];

    private int $nextContactId = 1;

    private int $nextAccountId = 1;

    public function failContactOn(string $lookupValue): void
    {
        $this->failContactLookups[$lookupValue] = true;
    }

    public function failAccountOn(string $lookupValue): void
    {
        $this->failAccountLookups[$lookupValue] = true;
    }

    public function findContact(string $id): ?Contact
    {
        return $this->contacts[$id] ?? null;
    }

    public function findContactBy(array $criteria): ?Contact
    {
        foreach ($this->contacts as $c) {
            if ($this->matches($c, $criteria)) {
                return $c;
            }
        }

        return null;
    }

    public function createContact(Contact $contact): Contact
    {
        $id = 'cc-contact-' . $this->nextContactId++;
        $stored = Contact::fromArray(array_merge($contact->toArray(), ['id' => $id]));
        $this->contacts[$id] = $stored;

        return $stored;
    }

    public function updateContact(string $id, Contact $contact): Contact
    {
        $stored = Contact::fromArray(array_merge($contact->toArray(), ['id' => $id]));
        $this->contacts[$id] = $stored;

        return $stored;
    }

    public function upsertContact(string $lookupField, Contact $contact): UpsertResult
    {
        $lookupValue = (string) $contact->get($lookupField);
        if (isset($this->failContactLookups[$lookupValue])) {
            throw new \RuntimeException('Simulated CC failure for contact ' . $lookupValue);
        }

        $existing = $this->findContactBy([$lookupField => $lookupValue]);
        if ($existing !== null && $existing->getId() !== null) {
            return new UpsertResult($this->updateContact($existing->getId(), $contact));
        }

        return new UpsertResult($this->createContact($contact), created: true);
    }

    public function findAccount(string $id): ?Account
    {
        return $this->accounts[$id] ?? null;
    }

    public function findAccountBy(array $criteria): ?Account
    {
        foreach ($this->accounts as $a) {
            if ($this->matches($a, $criteria)) {
                return $a;
            }
        }

        return null;
    }

    public function createAccount(Account $account): Account
    {
        $id = 'cc-account-' . $this->nextAccountId++;
        $stored = Account::fromArray(array_merge($account->toArray(), ['id' => $id]));
        $this->accounts[$id] = $stored;

        return $stored;
    }

    public function updateAccount(string $id, Account $account): Account
    {
        $stored = Account::fromArray(array_merge($account->toArray(), ['id' => $id]));
        $this->accounts[$id] = $stored;

        return $stored;
    }

    public function upsertAccount(string $lookupField, Account $account): UpsertResult
    {
        $lookupValue = (string) $account->get($lookupField);
        if (isset($this->failAccountLookups[$lookupValue])) {
            throw new \RuntimeException('Simulated CC failure for account ' . $lookupValue);
        }

        $existing = $this->findAccountBy([$lookupField => $lookupValue]);
        if ($existing !== null && $existing->getId() !== null) {
            return new UpsertResult($this->updateAccount($existing->getId(), $account));
        }

        return new UpsertResult($this->createAccount($account), created: true);
    }

    public function findActivity(string $id, ActivityType $type): ?Activity
    {
        return null;
    }

    public function iterateActivities(ActivityType $type, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from [];
    }

    public function iterateEntity(
        string $entityType,
        ?\DateTimeImmutable $since = null,
        int $offset = 0,
        array $filters = [],
        string $sinceField = 'edited',
    ): \Generator {
        yield from [];
    }

    public function ping(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $criteria */
    private function matches(Contact|Account $entity, array $criteria): bool
    {
        foreach ($criteria as $field => $value) {
            if ((string) $entity->get($field) !== (string) $value) {
                return false;
            }
        }

        return true;
    }
}
