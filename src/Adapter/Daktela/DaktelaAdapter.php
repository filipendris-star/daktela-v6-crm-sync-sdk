<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter\Daktela;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Exception\AdapterException;
use Daktela\DaktelaV6\Client;
use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\RequestFactory;
use Psr\Log\LoggerInterface;

final class DaktelaAdapter implements ContactCentreAdapterInterface
{
    private const ACTIVITIES_MODEL = 'Activities';

    /** @var array<string, string|null> user-login-by-email cache, keyed by lowercased email (per run) */
    private array $userLoginCache = [];

    private readonly Client $client;

    public function __construct(
        string $instanceUrl,
        string $accessToken,
        private readonly string $database,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new Client($instanceUrl, $accessToken);
    }

    public function findContact(string $id): ?Contact
    {
        return $this->findEntity('Contacts', $id, fn (array $data) => Contact::fromArray($data));
    }

    /** @param array<string, mixed> $criteria */
    public function findContactBy(array $criteria): ?Contact
    {
        return $this->findEntityBy('Contacts', $criteria, fn (array $data) => Contact::fromArray($data));
    }

    public function createContact(Contact $contact): Contact
    {
        $data = $this->createEntity('Contacts', $this->prepareContactData($contact->toArray()));

        return Contact::fromArray($data);
    }

    public function updateContact(string $id, Contact $contact): Contact
    {
        $data = $this->updateEntity('Contacts', $id, $this->prepareContactData($contact->toArray()));

        return Contact::fromArray($data);
    }

    public function upsertContact(string $lookupField, Contact $contact): UpsertResult
    {
        $lookupValue = $contact->get($lookupField);
        if ($lookupValue === null) {
            throw AdapterException::missingId('contact');
        }

        // Resolve the owner ONCE, here. The payload this produces is both what the
        // change comparison sees and what gets written, so login is compared against
        // login (unchanged contacts stay skipped instead of being re-updated every
        // run) without the write path resolving a second time.
        [$prepared, $ownerLookupFailed] = $this->resolveContactOwner($contact->getData());

        $existing = $this->findContactBy([$lookupField => (string) $lookupValue]);

        if ($existing !== null && $existing->getId() !== null) {
            // A failed owner LOOKUP is never reported as "no changes": nothing is
            // known about the owner, and an unchanged CRM record does not come back
            // into the incremental window to be retried. A genuine not-found is
            // already absent from $prepared, so it compares equal and skips.
            if (!$ownerLookupFailed && !$this->hasChanges($existing->getData(), $prepared)) {
                $this->logger->debug('Skip contact update: no changes', ['id' => $existing->getId()]);

                return new UpsertResult($existing, skipped: true);
            }

            return new UpsertResult(
                Contact::fromArray($this->updateEntity('Contacts', $existing->getId(), $prepared)),
            );
        }

        return new UpsertResult(Contact::fromArray($this->createEntity('Contacts', $prepared)), created: true);
    }

    public function findAccount(string $id): ?Account
    {
        return $this->findEntity('Accounts', $id, fn (array $data) => Account::fromArray($data));
    }

    /** @param array<string, mixed> $criteria */
    public function findAccountBy(array $criteria): ?Account
    {
        return $this->findEntityBy('Accounts', $criteria, fn (array $data) => Account::fromArray($data));
    }

    public function createAccount(Account $account): Account
    {
        $data = $this->createEntity('Accounts', $account->toArray());

        return Account::fromArray($data);
    }

    public function updateAccount(string $id, Account $account): Account
    {
        $data = $this->updateEntity('Accounts', $id, $account->toArray());

        return Account::fromArray($data);
    }

    public function upsertAccount(string $lookupField, Account $account): UpsertResult
    {
        $lookupValue = $account->get($lookupField);
        if ($lookupValue === null) {
            throw AdapterException::missingId('account');
        }

        $existing = $this->findAccountBy([$lookupField => (string) $lookupValue]);

        if ($existing !== null && $existing->getId() !== null) {
            if (!$this->hasChanges($existing->getData(), $account->getData())) {
                $this->logger->debug('Skip account update: no changes', ['id' => $existing->getId()]);

                return new UpsertResult($existing, skipped: true);
            }

            return new UpsertResult($this->updateAccount($existing->getId(), $account));
        }

        return new UpsertResult($this->createAccount($account), created: true);
    }

    public function findActivity(string $id, ActivityType $type): ?Activity
    {
        /** @var Activity|null */
        return $this->findEntity(self::ACTIVITIES_MODEL, $id, function (array $data) use ($type): Activity {
            $activity = Activity::fromArray($this->flattenActivityRow($data));
            $activity->setActivityType($type);

            return $activity;
        });
    }

    /**
     * Prepare contact data for the write API. A `user` value that still looks
     * like an email (mapped from a CRM owner but not resolvable to a Daktela
     * user) is dropped rather than sent — the API would reject the whole
     * contact for an unknown user reference, and we'd rather sync the contact
     * without touching its owner.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareContactData(array $data): array
    {
        return $this->resolveContactOwner($data)[0];
    }

    /**
     * The ONE place a contact's owner email is turned into a Daktela user login.
     *
     * Resolution used to happen twice per contact — once in upsertContact() so the
     * change comparison could compare login against login, and again on the write
     * path — with the second call papered over by seeding the cache with
     * `login => login` so it resolved to itself. Two lookups per contact where
     * logins are email-shaped, and a valid login could be negative-cached on a miss.
     * Resolving once and carrying the payload to the write removes both.
     *
     * The second return value distinguishes the two ways `user` can be dropped,
     * which callers must not conflate:
     *   false — the CRM answered and there is no such Daktela user. Dropping the
     *           owner is final, so the comparison must drop it too, or the contact
     *           reads as changed on every run and a no-op PUT bumps `edited` forever.
     *   true  — the lookup could not be performed (API fault). Nothing is known, so
     *           the contact must NOT be reported unchanged: skipping it here is what
     *           silently eats an owner-only change, since an unchanged CRM record
     *           does not re-enter the incremental window to be retried.
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: bool} [payload, ownerLookupFailed]
     */
    private function resolveContactOwner(array $data): array
    {
        $user = $data['user'] ?? null;
        if (!is_string($user) || !str_contains($user, '@')) {
            return [$data, false];
        }

        $lookupFailed = false;
        $login = $this->findUserLoginByEmail($user, $lookupFailed);
        if ($login !== null) {
            $data['user'] = $login;

            return [$data, false];
        }

        if ($lookupFailed) {
            $this->logger->warning(
                'Could not look up the Daktela user for {email} — the contact is written without '
                . 'touching its owner, and the owner is applied on a later run.',
                ['email' => $user],
            );
        } else {
            $this->logger->warning('No Daktela user with email {email} — leaving contact owner untouched', [
                'email' => $user,
            ]);
        }

        unset($data['user']);

        return [$data, $lookupFailed];
    }

    /**
     * Resolve a Daktela user login by email (notification email first, auth
     * email as fallback). Cached per adapter instance (one sync run).
     *
     * @param bool $lookupFailed set to true when the lookup could not be performed,
     *        as opposed to answering "no such user"
     */
    private function findUserLoginByEmail(string $email, bool &$lookupFailed = false): ?string
    {
        $cacheKey = strtolower($email);
        if (array_key_exists($cacheKey, $this->userLoginCache)) {
            return $this->userLoginCache[$cacheKey];
        }

        $login = null;
        $lookupFailed = false;

        foreach (['email', 'emailAuth'] as $field) {
            $request = RequestFactory::buildReadRequest('Users');
            $request->addFilter($field, 'eq', $email);
            $request->setTake(1);

            $response = $this->client->execute($request);
            // Same three-part test as the enumerations: a result-less body reports
            // no errors and null data, and treating that as "no such user" would
            // negative-cache a transient failure for the whole run — stripping the
            // owner from every contact behind it, which is what this flag exists
            // to prevent.
            if (!$this->isQueryable($response)) {
                $lookupFailed = true;
                continue;
            }

            $data = $response->getData();
            if (is_array($data) && $data !== []) {
                $row = (array) $data[0];
                $login = isset($row['name']) ? (string) $row['name'] : null;
                if ($login !== null) {
                    break;
                }
            }
        }

        // Only cache a negative result when every lookup genuinely returned
        // "not found" — caching a transient API failure as null would strip the
        // owner from every subsequent record in the run.
        if ($login === null && $lookupFailed) {
            return null;
        }

        return $this->userLoginCache[$cacheKey] = $login;
    }

    /**
     * Flatten the nested relations of an Activities row so field mappings can
     * address them as scalars:
     *  - user    -> user_email / user_login / user_title
     *  - contact -> contact_name
     *  - item    -> item_<field> for every scalar field of the polymorphic
     *               per-type record (item_direction, item_answered, ...).
     *               `item` is type-specific (call, sms, email, ...), so the
     *               available item_* fields differ per activity type.
     *
     * Flattening only. Every key here is a field the platform actually returned,
     * carrying the value it returned — nothing is derived, combined or normalised.
     *
     * That boundary is deliberate. This adapter's job is to present Daktela's data;
     * deciding what a combination of fields MEANS to a given CRM is the CRM
     * adapter's job, and a derived token shaped by one CRM's vocabulary does not
     * belong in a universal SDK. A CRM that needs, say, a single call-state value
     * from `item_direction` × `item_answered` maps both fields through and combines
     * them in its own adapter. See docs/03-field-mapping.md, "Deriving values the
     * mapping engine cannot express".
     *
     * One quirk worth knowing when you do: the platform is not consistent about
     * direction casing — call and email items store 'in'/'out' while the chat family
     * (web, fbm, wap, viber) stores 'IN'/'OUT'. Case-fold before comparing.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function flattenActivityRow(array $row): array
    {
        if (isset($row['user']) && (is_array($row['user']) || is_object($row['user']))) {
            $user = (array) $row['user'];
            // Prefer notification email, fall back to auth email
            $email = !empty($user['email']) ? $user['email'] : null;
            $emailAuth = !empty($user['emailAuth']) ? $user['emailAuth'] : null;
            $row['user_email'] = $email ?? $emailAuth;
            $row['user_login'] = $user['name'] ?? null;
            $row['user_title'] = $user['title'] ?? null;
        }

        if (isset($row['contact']) && (is_array($row['contact']) || is_object($row['contact']))) {
            $contact = (array) $row['contact'];
            $row['contact_name'] = $contact['name'] ?? null;
        }

        if (isset($row['item']) && (is_array($row['item']) || is_object($row['item']))) {
            foreach ((array) $row['item'] as $field => $value) {
                if ($value === null || is_scalar($value)) {
                    $row['item_' . $field] = $value;
                }
            }
        }

        return $row;
    }

    /** @return \Generator<int, Activity> */
    public function iterateActivities(ActivityType $type, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        $request = RequestFactory::buildReadRequest(self::ACTIVITIES_MODEL);
        $request->addFilter('type', 'eq', $type->apiValue());
        // Only closed activities sync outward: they are terminal, so one export is
        // enough and no later update is needed.
        $request->addFilter('action', 'eq', 'CLOSE');

        // Activities have no `edited`, so there is no single monotonic change
        // marker — and BOTH candidates have a blind spot:
        //
        //  - `time` (the start) misses an activity that started before the
        //    watermark and closed after it.
        //  - `time_close` misses one whose close time is older than its close
        //    EVENT. The platform's postpone path sets time_close to the postpone
        //    time, and its close path then SKIPS time_close when the previous
        //    action was POSTPONE (Activities::setCloseAttributes), so a
        //    postponed-then-closed activity carries a stale one. Custom
        //    activities can also be written with a back-dated time_close.
        //
        // Either field entering the window is enough, so match on either. That
        // covers strictly more than `time` alone did, and the adapter's upsert
        // dedupes the overlap.
        //
        // It is NOT complete, and the residual case is worth knowing because the
        // loss is silent and permanent. Neither timestamp moves when an activity
        // CLOSES after being postponed, so an activity postponed before a run and
        // closed after it has both fields behind the watermark and is never
        // returned by any later run — only forceFullSync recovers it. Postponing
        // applies to email, SMS and the chat channels, never to calls. There is no
        // field to fix this with: activities carry no `edited`, and `action`
        // changing to CLOSE is not a timestamp. A deployment that postpones
        // heavily should schedule a periodic forced run. Nested OR group under the top-level AND — the
        // shape the v6 filter tree expects.
        if ($since !== null) {
            $sinceValue = $since->format('Y-m-d H:i:s');
            $request->addFilterFromArray(['filters' => [[
                'logic' => 'or',
                'filters' => [
                    ['field' => 'time_close', 'operator' => 'gte', 'value' => $sinceValue],
                    ['field' => 'time', 'operator' => 'gte', 'value' => $sinceValue],
                ],
            ]]]);
        }

        // Stable ordering — offset pagination across pages (and across batches in
        // the sync layer) needs deterministic row positions.
        $request->addSort('name', 'asc');

        $pageSize = 100;
        $currentOffset = $offset;

        while (true) {
            $pageRequest = clone $request;
            $pageRequest->setSkip($currentOffset);
            $pageRequest->setTake($pageSize);

            $response = $this->client->execute($pageRequest);

            // A swallowed API error — including one arriving as a result-less
            // body with no errors at all — would let the incremental window
            // advance over records that were never read.
            $this->assertQueryable($response, 'activity');

            if ($response->isEmpty()) {
                return;
            }

            $data = $response->getData();
            if (!is_array($data) || $data === []) {
                return;
            }

            foreach ($data as $item) {
                $row = is_array($item) ? $item : (array) $item;
                $row['id'] = $row['name'] ?? $row['id'] ?? null;
                $row = $this->flattenActivityRow($row);

                $activity = Activity::fromArray($row);
                $activity->setActivityType($type);

                yield $activity;
            }

            if (count($data) < $pageSize) {
                return;
            }

            $currentOffset += $pageSize;
        }
    }

    public function ping(): bool
    {
        return $this->client->ping();
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     */
    private function hasChanges(array $existing, array $new): bool
    {
        foreach ($new as $key => $value) {
            $existingValue = $existing[$key] ?? null;
            if ($this->normalizeValue($existingValue) != $this->normalizeValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a value for comparison to account for Daktela API transformations:
     * - stdClass is cast to array (Daktela client uses json_decode without assoc flag)
     * - Entity reference objects ({name: "x", title: "..."}) are reduced to the name value
     * - Single-element lists are unwrapped (Daktela wraps some string fields in arrays)
     * - Associative arrays are normalized recursively (e.g. customFields)
     * - Non-alphabetic strings (phones, codes) have whitespace fully stripped
     * - Text strings (containing letters) are trimmed with internal whitespace collapsed
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }

        if (is_array($value) && count($value) === 1 && array_is_list($value)) {
            $value = $value[0];
        }

        // Daktela returns relation fields as full objects ({name, title, ...}).
        // We only send the name identifier, so reduce to that for comparison.
        if (is_array($value) && !array_is_list($value) && isset($value['name'])) {
            $value = $value['name'];
        }

        if (is_array($value)) {
            $normalized = array_map(fn ($v) => $this->normalizeValue($v), $value);
            if (array_is_list($normalized)) {
                sort($normalized);
            }

            return $normalized;
        }

        if (is_string($value)) {
            // Date/datetime strings: normalize to canonical format
            if (preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', $value)) {
                $ts = strtotime($value);
                if ($ts !== false) {
                    return date('Y-m-d H:i:s', $ts);
                }
            }

            // Non-alphabetic strings (phones, numeric codes): strip all whitespace
            // Text strings (names, descriptions): trim + collapse internal whitespace
            if (!preg_match('/[a-zA-Z]/', $value)) {
                return preg_replace('/\s+/', '', $value);
            }

            return preg_replace('/\s+/', ' ', trim($value));
        }

        return $value;
    }

    /**
     * @template T
     * @param callable(array<string, mixed>): T $factory
     * @return T|null
     */
    private function findEntity(string $model, string $id, callable $factory): mixed
    {
        try {
            $request = RequestFactory::buildReadSingleRequest($model, $id);
            $response = $this->client->execute($request);

            // null means "this record does not exist", and nothing else. A lookup
            // that could not be performed must throw: read as absence it makes the
            // caller create a SECOND Daktela record for one that already exists,
            // report it Created, and let the watermark advance past it. The
            // result-less shape is the dangerous one — a body with no `result` key
            // arrives as a 2xx with no errors and null data (see isQueryable()).
            $this->assertQueryable($response, $model);

            if ($response->isEmpty()) {
                return null;
            }

            $data = $response->getData();
            $data = is_array($data) ? $data : (array) $data;
            $data['id'] = $data['name'] ?? $id;

            return $factory($data);
        } catch (RequestException $e) {
            // A 404 IS the answer; anything else is the lookup failing.
            if ($e->getCode() !== 404) {
                throw AdapterException::queryFailed($model, $e->getMessage());
            }

            $this->logger->debug('Entity not found: {model} {id}', [
                'model' => $model,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @template T
     * @param array<string, mixed> $criteria
     * @param callable(array<string, mixed>): T $factory
     * @return T|null
     */
    private function findEntityBy(string $model, array $criteria, callable $factory): mixed
    {
        try {
            $request = RequestFactory::buildReadRequest($model);
            $request->setTake(1);

            foreach ($criteria as $field => $value) {
                $request->addFilter($field, 'eq', $value);
            }

            $response = $this->client->execute($request);

            // See findEntity(): this is the lookup upsertContact()/upsertAccount()
            // use to decide create-vs-update, so a failure reported as "not found"
            // duplicates the record.
            $this->assertQueryable($response, $model);

            $items = $response->getData();
            if (!is_array($items) || $items === []) {
                return null;
            }

            $data = is_array(reset($items)) ? reset($items) : (array) reset($items);
            $data['id'] = $data['name'] ?? null;

            return $factory($data);
        } catch (RequestException $e) {
            if ($e->getCode() !== 404) {
                throw AdapterException::queryFailed($model, $e->getMessage());
            }

            $this->logger->debug('Entity lookup failed: {model}', [
                'model' => $model,
                'criteria' => $criteria,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function createEntity(string $model, array $attributes): array
    {
        if (in_array($model, ['Contacts', 'Accounts'], true)) {
            $attributes['database'] = $this->database;
        }

        try {
            $request = RequestFactory::buildCreateRequest($model);
            $request->addAttributes($attributes);
            $response = $this->client->execute($request);

            if (!$response->isSuccess() || $response->hasErrors()) {
                throw AdapterException::createFailed(
                    $model,
                    detail: $this->formatResponseErrors($response),
                );
            }

            $data = $response->getData();
            $data = is_array($data) ? $data : (array) $data;

            // A create must come back NAMING the record. Everything downstream
            // needs that id — write-back and relation maps — and
            // returning ['id' => null] reported the record Created with a null
            // target while the write's outcome was in fact unknown, letting the
            // watermark advance over it. Two shapes land here: a body with no
            // `result` key (an empty 2xx, a proxy page), which the connector turns
            // into null data that survives isSuccess(); and a bare `result: true`,
            // which casts to an array carrying no name.
            //
            // Failing is also safe to retry: upsert looks the record up before
            // creating, so if the create did land, the retry updates it.
            if (!isset($data['name']) || (string) $data['name'] === '') {
                throw AdapterException::createFailed(
                    $model,
                    detail: sprintf('HTTP %d response named no created record', $response->getHttpStatus()),
                );
            }

            $data['id'] = (string) $data['name'];

            return $data;
        } catch (RequestException $e) {
            throw AdapterException::createFailed($model, $e, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function updateEntity(string $model, string $id, array $attributes): array
    {
        if (in_array($model, ['Contacts', 'Accounts'], true)) {
            $attributes['database'] = $this->database;
        }

        try {
            $request = RequestFactory::buildUpdateRequest($model);
            $request->setObjectName($id);
            $request->addAttributes($attributes);
            $response = $this->client->execute($request);

            if (!$response->isSuccess() || $response->hasErrors()) {
                throw AdapterException::updateFailed(
                    $model,
                    $id,
                    detail: $this->formatResponseErrors($response),
                );
            }

            $data = $response->getData();

            // Unlike a create, an update already knows WHICH record it wrote, so
            // a response that merely confirms it is enough. What is not enough is
            // no response body at all: null data means the connector found no
            // `result` key, so nothing here affirms the write happened, and
            // returning the id we were handed reported a garbage response as a
            // successful update.
            if ($data === null) {
                throw AdapterException::updateFailed(
                    $model,
                    $id,
                    detail: sprintf('HTTP %d response carried no record', $response->getHttpStatus()),
                );
            }

            $data = is_array($data) ? $data : (array) $data;
            $data['id'] = $data['name'] ?? $id;

            return $data;
        } catch (RequestException $e) {
            throw AdapterException::updateFailed($model, $id, $e, $e->getMessage());
        }
    }

    /**
     * Did this read actually return a result set?
     *
     * Three ways it can fail, and only the first is what most code checks:
     * an error envelope (hasErrors), a non-2xx status, and — the quiet one — a
     * body with no `result` key at all, which the connector turns into
     * Response(null, 0, [], status). That last shape has an empty error array,
     * so hasErrors() is false and isEmpty() is true: indistinguishable from a
     * legitimate empty page unless the null data is checked, because a real
     * empty page carries `result.data: []` (an array).
     */
    private function isQueryable(\Daktela\DaktelaV6\Response\Response $response): bool
    {
        return $response->isSuccess()
            && !$response->hasErrors()
            && $response->getData() !== null;
    }

    /** {@see isQueryable()}; throws instead of reporting, for the enumerations. */
    private function assertQueryable(\Daktela\DaktelaV6\Response\Response $response, string $entityType): void
    {
        if ($this->isQueryable($response)) {
            return;
        }

        $detail = $response->hasErrors()
            ? $this->formatResponseErrors($response)
            : sprintf('HTTP %d with no result envelope', $response->getHttpStatus());

        throw AdapterException::queryFailed($entityType, $detail);
    }

    private function formatResponseErrors(\Daktela\DaktelaV6\Response\Response $response): string
    {
        $errors = $response->getErrors();
        if ($errors === []) {
            return sprintf('HTTP %d', $response->getHttpStatus());
        }

        $messages = [];
        foreach ($errors as $error) {
            if (is_array($error)) {
                $messages[] = json_encode($error, JSON_UNESCAPED_UNICODE);
            } else {
                $messages[] = (string) $error;
            }
        }

        return implode('; ', $messages);
    }
}
