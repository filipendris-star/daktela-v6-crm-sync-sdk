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

        // Resolve a mapped owner email (e.g. CRM owner -> Daktela user) to the
        // user login up-front so hasChanges() compares login against login and
        // unchanged contacts are skipped instead of re-updated every run.
        $userValue = $contact->get('user');
        if (is_string($userValue) && str_contains($userValue, '@')) {
            $resolved = $this->findUserLoginByEmail($userValue);
            if ($resolved !== null) {
                $contact->set('user', $resolved);
            }
        }

        $existing = $this->findContactBy([$lookupField => (string) $lookupValue]);

        if ($existing !== null && $existing->getId() !== null) {
            if (!$this->hasChanges($existing->getData(), $contact->getData())) {
                $this->logger->debug('Skip contact update: no changes', ['id' => $existing->getId()]);

                return new UpsertResult($existing, skipped: true);
            }

            return new UpsertResult($this->updateContact($existing->getId(), $contact));
        }

        return new UpsertResult($this->createContact($contact), created: true);
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
        $user = $data['user'] ?? null;
        if (!is_string($user) || !str_contains($user, '@')) {
            return $data;
        }

        $login = $this->findUserLoginByEmail($user);
        if ($login !== null) {
            $data['user'] = $login;

            return $data;
        }

        $this->logger->warning('No Daktela user with email {email} — leaving contact owner untouched', [
            'email' => $user,
        ]);
        unset($data['user']);

        return $data;
    }

    /**
     * Resolve a Daktela user login by email (notification email first, auth
     * email as fallback). Cached per adapter instance (one sync run).
     */
    private function findUserLoginByEmail(string $email): ?string
    {
        $cacheKey = strtolower($email);
        if (array_key_exists($cacheKey, $this->userLoginCache)) {
            return $this->userLoginCache[$cacheKey];
        }

        $login = null;

        foreach (['email', 'emailAuth'] as $field) {
            $request = RequestFactory::buildReadRequest('Users');
            $request->addFilter($field, 'eq', $email);
            $request->setTake(1);

            $response = $this->client->execute($request);
            if ($response->hasErrors()) {
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
        $request->addFilter('type', 'eq', strtoupper($type->value));

        if ($since !== null) {
            $request->addFilter('time', 'gte', $since->format('Y-m-d H:i:s'));
        }

        $pageSize = 100;
        $currentOffset = $offset;

        while (true) {
            $pageRequest = clone $request;
            $pageRequest->setSkip($currentOffset);
            $pageRequest->setTake($pageSize);

            $response = $this->client->execute($pageRequest);

            if ($response->hasErrors() || $response->isEmpty()) {
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

            if (!$response->isSuccess() || $response->isEmpty()) {
                return null;
            }

            $data = $response->getData();
            $data = is_array($data) ? $data : (array) $data;
            $data['id'] = $data['name'] ?? $id;

            return $factory($data);
        } catch (RequestException $e) {
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

            if (!$response->isSuccess() || $response->isEmpty()) {
                return null;
            }

            $items = $response->getData();
            if (!is_array($items) || $items === []) {
                return null;
            }

            $data = is_array(reset($items)) ? reset($items) : (array) reset($items);
            $data['id'] = $data['name'] ?? null;

            return $factory($data);
        } catch (RequestException $e) {
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

            if (!$response->isSuccess()) {
                throw AdapterException::createFailed(
                    $model,
                    detail: $this->formatResponseErrors($response),
                );
            }

            $data = $response->getData();
            $data = is_array($data) ? $data : (array) $data;
            $data['id'] = $data['name'] ?? null;

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

            if (!$response->isSuccess()) {
                throw AdapterException::updateFailed(
                    $model,
                    $id,
                    detail: $this->formatResponseErrors($response),
                );
            }

            $data = $response->getData();
            $data = is_array($data) ? $data : (array) $data;
            $data['id'] = $data['name'] ?? $id;

            return $data;
        } catch (RequestException $e) {
            throw AdapterException::updateFailed($model, $id, $e, $e->getMessage());
        }
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
