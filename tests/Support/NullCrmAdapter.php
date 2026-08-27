<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Support;

use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\Contact;

/**
 * Every CrmAdapterInterface method, doing nothing. Extend it in a test that
 * needs to implement one or two methods for real without restating the other
 * fifteen — and, unlike a PHPUnit mock, it can be extended to also implement an
 * optional capability interface.
 */
abstract class NullCrmAdapter implements CrmAdapterInterface
{
    public function findContact(string $id): ?Contact { return null; }
    public function findContactByLookup(string $field, string $value): ?Contact { return null; }
    public function iterateContacts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator { yield from []; }
    public function findAccount(string $id): ?Account { return null; }
    public function findAccountByLookup(string $field, string $value): ?Account { return null; }
    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator { yield from []; }
    public function searchContacts(string $query): \Generator { yield from []; }
    public function searchAccounts(string $query): \Generator { yield from []; }
    public function findActivity(string $id): ?Activity { return null; }
    public function findActivityByLookup(string $field, string $value): ?Activity { return null; }
    public function createActivity(Activity $activity): Activity { return $activity; }
    public function updateActivity(string $id, Activity $activity): Activity { return $activity; }
    public function upsertActivity(string $lookupField, Activity $activity): Activity { return $activity; }
    public function ping(): bool { return true; }
    public function iterateCustomEntity(string $entityName, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator { yield from []; }
    public function findCustomEntity(string $entityName, string $id): ?array { return null; }
    public function findCustomEntityByLookup(string $entityName, string $field, string $value): ?array { return null; }
}
