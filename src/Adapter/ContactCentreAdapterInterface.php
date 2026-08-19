<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;

interface ContactCentreAdapterInterface
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

    public function ping(): bool;
}
