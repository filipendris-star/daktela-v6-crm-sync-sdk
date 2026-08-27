<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\Contact;

/**
 * The find* methods below must distinguish absence from failure: return null
 * ONLY when the CRM answered and the record is genuinely not there, and throw
 * (AdapterException) when the answer could not be obtained — timeout, 5xx,
 * revoked scope, missing endpoint.
 *
 * This is not cosmetic. Relation resolution treats null as "there is nothing to
 * resolve to" and passes the raw CRM foreign key through to Daktela (see
 * docs/03, "How It Works" step 5). An adapter that returns null during an
 * outage therefore writes a raw CRM id into a Daktela relation field and reports
 * the record synced. Throwing instead fails that one record, so it is reported
 * rather than silently mislinked — note that a failed record is not retried
 * automatically (docs/07: the watermark still advances when other records
 * succeeded), so getting this distinction right is what keeps bad links out of
 * Daktela in the first place.
 */
interface CrmAdapterInterface
{
    // Contacts (read-only — CRM is source-of-truth)
    /** @return ?Contact null only if no such contact exists; throws if the CRM could not be asked */
    public function findContact(string $id): ?Contact;

    public function findContactByLookup(string $field, string $value): ?Contact;

    /** @return \Generator<int, Contact> */
    public function iterateContacts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator;

    // Accounts (read-only — CRM is source-of-truth)
    /** @return ?Account null only if no such account exists; throws if the CRM could not be asked */
    public function findAccount(string $id): ?Account;

    public function findAccountByLookup(string $field, string $value): ?Account;

    /** @return \Generator<int, Account> */
    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator;

    // Fulltext search
    /** @return \Generator<int, Contact> */
    public function searchContacts(string $query): \Generator;

    /** @return \Generator<int, Account> */
    public function searchAccounts(string $query): \Generator;

    // Activities (writable — CC is source-of-truth, CRM receives data)
    public function findActivity(string $id): ?Activity;

    public function findActivityByLookup(string $field, string $value): ?Activity;

    public function createActivity(Activity $activity): Activity;

    public function updateActivity(string $id, Activity $activity): Activity;

    /**
     * Find-then-write. This is the export path for EVERY activity: look the
     * record up by $lookupField and update it, or create it when absent.
     *
     * It is the only duplicate protection for activity export, so if your CRM
     * has no activity-search endpoint this must still fall back to
     * createActivity() rather than letting findActivityByLookup() throw — and
     * be aware that such a CRM will accumulate one record per run.
     */
    public function upsertActivity(string $lookupField, Activity $activity): Activity;

    public function ping(): bool;

    // Custom entities (read-only) — generic by-name access to any CRM-side object the adapter supports.
    // Used by sync.custom_entities[] to feed records into a Daktela target (contact / account / activity).
    // Returned arrays are flat associative records; the mapping layer handles the rest.
    // Adapters that don't support a given $entityName should throw NotSupportedException.

    /** @return \Generator<int, array<string, mixed>> */
    public function iterateCustomEntity(string $entityName, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator;

    /** @return array<string, mixed>|null */
    public function findCustomEntity(string $entityName, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findCustomEntityByLookup(string $entityName, string $field, string $value): ?array;
}
