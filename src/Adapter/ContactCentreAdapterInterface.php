<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;

/**
 * The Daktela (Contact Centre) side of a sync. Implement it to run the SDK
 * against something other than the shipped {@see Daktela\DaktelaAdapter}.
 * Methods are only ever ADDED here in a major release; newer needs go into
 * opt-in capability interfaces alongside it, e.g.
 * {@see SupportsEntityIterationInterface} for cc_to_crm custom entity export.
 */
interface ContactCentreAdapterInterface
{
    // Contacts (writable — CRM is source-of-truth, CC receives data)
    public function findContact(string $id): ?Contact;

    /** @param array<string, mixed> $criteria */
    public function findContactBy(array $criteria): ?Contact;

    public function createContact(Contact $contact): Contact;

    public function updateContact(string $id, Contact $contact): Contact;

    public function upsertContact(string $lookupField, Contact $contact): UpsertResult;

    // Accounts (writable — CRM is source-of-truth, CC receives data)
    public function findAccount(string $id): ?Account;

    /** @param array<string, mixed> $criteria */
    public function findAccountBy(array $criteria): ?Account;

    public function createAccount(Account $account): Account;

    public function updateAccount(string $id, Account $account): Account;

    public function upsertAccount(string $lookupField, Account $account): UpsertResult;

    // Activities (read-only — CC is source-of-truth)
    public function findActivity(string $id, ActivityType $type): ?Activity;

    /** @return \Generator<int, Activity> */
    public function iterateActivities(ActivityType $type, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator;

    public function ping(): bool;
}
