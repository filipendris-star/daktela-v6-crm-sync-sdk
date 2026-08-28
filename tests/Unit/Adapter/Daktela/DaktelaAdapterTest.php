<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Adapter\Daktela;

use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Exception\AdapterException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DaktelaAdapterTest extends TestCase
{
    public function testAdapterCanBeInstantiated(): void
    {
        $adapter = new DaktelaAdapter(
            'https://test.daktela.com',
            'test-token',
            'test-db',
            new NullLogger(),
        );

        self::assertInstanceOf(DaktelaAdapter::class, $adapter);
    }

    public function testActivitiesModelConstant(): void
    {
        $adapter = new DaktelaAdapter(
            'https://test.daktela.com',
            'test-token',
            'test-db',
            new NullLogger(),
        );

        // All activity types use the single 'Activities' model endpoint
        $reflection = new \ReflectionClass($adapter);
        $constant = $reflection->getConstant('ACTIVITIES_MODEL');

        self::assertSame('Activities', $constant);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hasChangesProvider')]
    public function testHasChanges(array $existing, array $new, bool $expected): void
    {
        $adapter = new DaktelaAdapter(
            'https://test.daktela.com',
            'test-token',
            'test-db',
            new NullLogger(),
        );

        $method = new \ReflectionMethod($adapter, 'hasChanges');

        self::assertSame($expected, $method->invoke($adapter, $existing, $new));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}> */
    public static function hasChangesProvider(): iterable
    {
        yield 'identical data' => [
            ['title' => 'John', 'email' => 'john@test.com'],
            ['title' => 'John', 'email' => 'john@test.com'],
            false,
        ];

        yield 'changed field' => [
            ['title' => 'John', 'email' => 'john@test.com'],
            ['title' => 'Jane', 'email' => 'john@test.com'],
            true,
        ];

        yield 'phone with spaces vs stripped' => [
            ['phone' => '+420553401520'],
            ['phone' => '+420 553 401 520'],
            false,
        ];

        yield 'url wrapped in array by Daktela' => [
            ['web' => ['https://example.com']],
            ['web' => 'https://example.com'],
            false,
        ];

        yield 'url array vs different string' => [
            ['web' => ['https://example.com']],
            ['web' => 'https://other.com'],
            true,
        ];

        yield 'extra fields in existing are ignored' => [
            ['title' => 'John', 'database' => 'main', 'extra' => 'foo'],
            ['title' => 'John'],
            false,
        ];

        yield 'loose type comparison int vs string' => [
            ['priority' => 123],
            ['priority' => '123'],
            false,
        ];

        yield 'nested customFields with phone spaces and url array' => [
            ['customFields' => ['phone' => '+420553401520', 'web' => ['https://example.com']]],
            ['customFields' => ['phone' => '+420 553 401 520', 'web' => 'https://example.com']],
            false,
        ];

        yield 'nested customFields with actual change' => [
            ['customFields' => ['phone' => '+420553401520']],
            ['customFields' => ['phone' => '+421999888777']],
            true,
        ];

        yield 'stdClass from json_decode treated as array' => [
            ['customFields' => (object) ['phone' => '+420553401520', 'web' => ['https://example.com']]],
            ['customFields' => ['phone' => '+420 553 401 520', 'web' => 'https://example.com']],
            false,
        ];

        yield 'text field whitespace change is detected' => [
            ['title' => 'JohnDoe'],
            ['title' => 'John Doe'],
            true,
        ];

        yield 'text field extra whitespace is collapsed' => [
            ['title' => 'John Doe'],
            ['title' => '  John   Doe  '],
            false,
        ];

        yield 'relation object reduced to name matches scalar' => [
            ['account' => ['name' => 'acme', 'title' => 'Acme Corp', 'database' => 'main']],
            ['account' => 'acme'],
            false,
        ];

        yield 'relation object with different name is detected' => [
            ['account' => ['name' => 'acme', 'title' => 'Acme Corp']],
            ['account' => 'globex'],
            true,
        ];

        yield 'relation stdClass object reduced to name' => [
            ['account' => (object) ['name' => 'acme', 'title' => 'Acme Corp']],
            ['account' => 'acme'],
            false,
        ];

        // Regression: commit 7ab3eeb — list arrays were reported as changed when
        // Daktela returned elements in a different order than we sent.
        yield 'list with reordered elements is not a change' => [
            ['tags' => ['alpha', 'beta', 'gamma']],
            ['tags' => ['gamma', 'alpha', 'beta']],
            false,
        ];

        yield 'list with different content is detected' => [
            ['tags' => ['alpha', 'beta']],
            ['tags' => ['alpha', 'delta']],
            true,
        ];

        yield 'nested list with reordered elements is not a change' => [
            ['customFields' => ['tags' => ['alpha', 'beta', 'gamma']]],
            ['customFields' => ['tags' => ['gamma', 'beta', 'alpha']]],
            false,
        ];

        // Regression: commit 7ab3eeb — Daktela returns "2024-01-15" for date-only
        // fields but we send "2024-01-15 00:00:00" (or vice versa), which was
        // reported as a change despite representing the same instant.
        yield 'date vs datetime midnight is not a change' => [
            ['birthday' => '2024-01-15'],
            ['birthday' => '2024-01-15 00:00:00'],
            false,
        ];

        yield 'datetime with and without seconds is not a change' => [
            ['last_contact' => '2024-01-15 14:30'],
            ['last_contact' => '2024-01-15 14:30:00'],
            false,
        ];

        yield 'ISO 8601 T separator is normalized to space' => [
            ['last_contact' => '2024-01-15T14:30:00'],
            ['last_contact' => '2024-01-15 14:30:00'],
            false,
        ];

        yield 'different dates are still detected as change' => [
            ['birthday' => '2024-01-15'],
            ['birthday' => '2024-01-16'],
            true,
        ];
    }

    /**
     * Flattening exposes what the platform returned, and derives nothing.
     *
     * The adapter used to synthesise an `item_call_state` token from
     * item_direction x item_answered. It was removed in 1.2.0: the token's
     * vocabulary was chosen to feed one CRM's value_map, and deciding what a
     * combination of Daktela fields MEANS to a given CRM belongs in that CRM's
     * adapter, not in the shared platform adapter. These tests pin the boundary so
     * the derivation cannot quietly return.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('itemFlatteningProvider')]
    public function testFlattenActivityRowExposesItemFieldsVerbatim(array $item, array $expected): void
    {
        $row = $this->flatten(['item' => $item]);

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $row);
            self::assertSame($value, $row[$key], "item field {$key}");
        }
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function itemFlatteningProvider(): iterable
    {
        yield 'scalars are prefixed and kept as-is' => [
            ['direction' => 'out', 'answered' => 1, 'text' => 'hi'],
            ['item_direction' => 'out', 'item_answered' => 1, 'item_text' => 'hi'],
        ];

        // The v6 API serialises flags as strings on some endpoints. The adapter must
        // not coerce them — a consumer keying a value_map needs to see "0", not false.
        yield 'string flags keep their string type' => [
            ['direction' => 'out', 'answered' => '0'],
            ['item_direction' => 'out', 'item_answered' => '0'],
        ];

        // The chat family (web, fbm, wap, viber) stores direction UPPERCASE
        // (BaseChatMapper::I_DIRECTION_IN = 'IN') while calls and emails store it
        // lowercase. The adapter reports both verbatim; folding is the consumer's
        // job, and docs/03 says so.
        yield 'uppercase chat direction is not folded' => [
            ['direction' => 'IN', 'answered' => 1],
            ['item_direction' => 'IN', 'item_answered' => 1],
        ];

        yield 'nulls survive' => [
            ['direction' => 'in', 'answered' => null],
            ['item_direction' => 'in', 'item_answered' => null],
        ];
    }

    public function testFlattenActivityRowDerivesNothing(): void
    {
        $row = $this->flatten(['item' => ['direction' => 'in', 'answered' => 0]]);

        self::assertArrayNotHasKey('item_call_state', $row, 'the removed derivation must not return');
        self::assertSame(
            ['item', 'item_direction', 'item_answered'],
            array_keys($row),
            'flattening adds item_* keys and nothing else',
        );
    }

    public function testFlattenActivityRowSkipsNonScalarItemFields(): void
    {
        // Only scalars are addressable by a mapping rule; a nested array would
        // arrive at the CRM as an unusable value.
        $row = $this->flatten(['item' => ['direction' => 'in', 'tags' => ['a', 'b']]]);

        self::assertArrayHasKey('item_direction', $row);
        self::assertArrayNotHasKey('item_tags', $row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function flatten(array $row): array
    {
        $adapter = new DaktelaAdapter(
            'https://test.daktela.com',
            'test-token',
            'test-db',
            new NullLogger(),
        );

        /** @var array<string, mixed> $flat */
        $flat = (new \ReflectionMethod($adapter, 'flattenActivityRow'))->invoke($adapter, $row);

        return $flat;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableResponseProvider')]
    public function testAnUnusableLookupResponseDoesNotReadAsNotFound(int $status, string $body): void
    {
        // This is the lookup upsertContact()/upsertAccount() use to decide
        // create-vs-update. Read as "not found", a failed lookup creates a SECOND
        // Daktela record for a contact that already exists, reports it Created,
        // and lets the watermark advance past it — for every record in the window
        // for as long as the condition holds.
        $adapter = $this->adapterWithHttpResponse($status, $body, __FUNCTION__ . $status);

        $this->expectException(AdapterException::class);
        $adapter->upsertContact('email', Contact::fromArray(['email' => 'j@t.com', 'title' => 'John']));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableResponseProvider')]
    public function testAnUnusableCreateResponseFailsInsteadOfReportingANamelessRecord(int $status, string $body): void
    {
        // The write side of the same defect: on a 2xx with no result envelope the
        // create returned ['id' => null], the record was reported Created with a
        // null target, and the watermark advanced over a write whose outcome was
        // unknown. The lookup answers "absent" here too, so this exercises create.
        $adapter = $this->adapterWithHttpResponse($status, $body, __FUNCTION__ . $status, lookupFindsNothing: true);

        $this->expectException(AdapterException::class);
        $adapter->createContact(Contact::fromArray(['email' => 'j@t.com', 'title' => 'John']));
    }

    public function testACreateAnsweredWithABareTrueIsNotReportedAsSuccess(): void
    {
        // `{"result":true}` survives isSuccess() and getData() !== null, then
        // casts to an array carrying no name — so the id came out null.
        $adapter = $this->adapterWithHttpResponse(200, '{"result":true}', __FUNCTION__, lookupFindsNothing: true);

        $this->expectException(AdapterException::class);
        $adapter->createContact(Contact::fromArray(['email' => 'j@t.com', 'title' => 'John']));
    }

    public function testAnUpdateWithNoResponseBodyFailsInsteadOfEchoingTheIdBack(): void
    {
        $adapter = $this->adapterWithHttpResponse(200, '', __FUNCTION__, lookupFindsNothing: true);

        $this->expectException(AdapterException::class);
        $adapter->updateContact('contact_1', Contact::fromArray(['title' => 'New']));
    }

    public function testAnUpdateConfirmedWithoutEchoingTheRecordStillSucceeds(): void
    {
        // An update already knows which record it wrote, so a body that merely
        // confirms the write is enough — the id falls back to the one we sent.
        // Guards against over-tightening the check above into "every update must
        // echo the record".
        $adapter = $this->adapterWithHttpResponse(200, '{"result":true}', __FUNCTION__, lookupFindsNothing: true);

        $updated = $adapter->updateContact('contact_1', Contact::fromArray(['title' => 'New']));

        self::assertSame('contact_1', $updated->getId());
    }

    public function testACreateThatNamesTheRecordSucceeds(): void
    {
        $adapter = $this->adapterWithHttpResponse(200, '{"result":{"name":"contact_9","title":"John"}}', __FUNCTION__, lookupFindsNothing: true);

        $created = $adapter->createContact(Contact::fromArray(['email' => 'j@t.com', 'title' => 'John']));

        self::assertSame('contact_9', $created->getId());
    }

    public function testTheActivityWindowMatchesEitherTimestamp(): void
    {
        // Neither activity timestamp is monotonic: `time` misses an activity that
        // started before the watermark and closed after it, and `time_close`
        // misses a postponed-then-closed one (the platform's close path skips
        // time_close when the previous action was POSTPONE, leaving the postpone
        // time behind). Matching on either removes both blind spots.
        $sent = null;
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->method('send')->willReturnCallback(
            function (\Psr\Http\Message\RequestInterface $request) use (&$sent) {
                $sent = urldecode($request->getUri()->getQuery());

                return new \GuzzleHttp\Psr7\Response(200, [], '{"result":{"data":[],"total":0}}');
            },
        );
        ApiCommunicator::getInstance('https://activitywindow.daktela.test', 'test-token')->setHttpClient($http);

        $adapter = new DaktelaAdapter('https://activitywindow.daktela.test', 'test-token', 'test-db', new NullLogger());
        iterator_to_array($adapter->iterateActivities(ActivityType::Call, new \DateTimeImmutable('2026-01-01 00:00:00')));

        self::assertIsString($sent);
        // The nested group sits under the top-level AND, so either timestamp
        // entering the window matches while `action = CLOSE` still gates.
        self::assertMatchesRegularExpression('/filter\[filters\]\[\d+\]\[logic\]=or/', $sent, 'the two timestamps must be OR-ed, not AND-ed');
        self::assertStringContainsString('[value]=CLOSE', $sent, 'still only closed activities');
        self::assertStringContainsString('[field]=time_close', $sent);
        self::assertMatchesRegularExpression('/\[filters\]\[\d+\]\[field\]=time&/', $sent, 'and plain `time` as the other arm');
        self::assertStringContainsString('filter[logic]=and', $sent, 'the OR group is nested, not the whole predicate');
    }

    /**
     * Regression: the adapter must filter on ActivityType::apiValue(), not
     * strtoupper($type->value). `web` upper-cases to WEB, which matches no row —
     * a configured web-chat export yielded nothing on every run while reporting
     * success and advancing the watermark.
     */
    public function testTheTypeFilterUsesThePlatformsOwnActivityTypeValue(): void
    {
        $cases = [
            [ActivityType::Chat, 'CHAT'],
            [ActivityType::Call, 'CALL'],
            [ActivityType::InstagramDm, 'IGDM'],
        ];

        foreach ($cases as [$type, $expected]) {
            $sent = null;
            $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
            $http->method('send')->willReturnCallback(
                function (\Psr\Http\Message\RequestInterface $request) use (&$sent) {
                    $sent = urldecode($request->getUri()->getQuery());

                    return new \GuzzleHttp\Psr7\Response(200, [], '{"result":{"data":[],"total":0}}');
                },
            );
            ApiCommunicator::getInstance('https://apivalue.daktela.test', 'test-token')->setHttpClient($http);

            $adapter = new DaktelaAdapter('https://apivalue.daktela.test', 'test-token', 'test-db', new NullLogger());
            iterator_to_array($adapter->iterateActivities($type));

            self::assertIsString($sent);
            self::assertStringContainsString(
                '[value]=' . $expected,
                $sent,
                sprintf('%s must filter on %s', $type->name, $expected),
            );
        }
    }

    public function testAGenuinelyAbsentRecordStillReadsAsNotFound(): void
    {
        // The other half: `result.data: []` is a real answer and must stay null,
        // or nothing could ever be created.
        $adapter = $this->adapterWithHttpResponse(200, '{"result":{"data":[],"total":0}}', __FUNCTION__);

        self::assertNull($adapter->findContact('nope'));
    }

    public function testAResolvedOwnerLoginIsNotReResolvedByTheWritePath(): void
    {
        // upsertContact resolves the mapped owner EMAIL to a Daktela login and
        // mutates the entity; the write path then re-triggers on any 'user'
        // containing '@' — now against that login. Where logins are email-shaped
        // this queried Users twice per contact and, finding nothing, stripped the
        // owner and negative-cached a valid login.
        $userQueries = 0;
        $writes = [];
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->method('send')->willReturnCallback(
            function (\Psr\Http\Message\RequestInterface $request) use (&$userQueries, &$writes) {
                $path = strtolower($request->getUri()->getPath());
                $method = $request->getMethod();

                if (str_contains($path, 'users')) {
                    $userQueries++;
                    // The login itself is email-shaped, and differs from the email
                    // it was looked up by.
                    return $this->jsonResponse(['result' => ['data' => [['name' => 'sales@daktela.local']], 'total' => 1]]);
                }

                if ($method !== 'GET') {
                    $writes[] = (string) $request->getBody();

                    return $this->jsonResponse(['result' => ['name' => 'contact_1']]);
                }

                return $this->jsonResponse(['result' => ['data' => [['name' => 'contact_1', 'title' => 'Old']]], 'total' => 1]);
            },
        );
        ApiCommunicator::getInstance('https://ownerlogin.daktela.test', 'test-token')->setHttpClient($http);

        $adapter = new DaktelaAdapter('https://ownerlogin.daktela.test', 'test-token', 'test-db', new NullLogger());
        $adapter->upsertContact('name', Contact::fromArray(['name' => 'contact_1', 'title' => 'New', 'user' => 'owner@acme.com']));

        self::assertSame(1, $userQueries, 'the login must not be looked up a second time as if it were an email');
        self::assertCount(1, $writes);
        self::assertStringContainsString('sales@daktela.local', $writes[0], 'the resolved owner must survive to the payload');
    }

    public function testAnUnchangedContactWithAResolvedOwnerIsSkipped(): void
    {
        // The reason the owner is resolved BEFORE the comparison: the CC record
        // stores the login, the mapped record carries the email. Compared as-is,
        // every contact looks changed and a no-op PUT bumps `edited` forever.
        $writes = [];
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->method('send')->willReturnCallback(
            function (\Psr\Http\Message\RequestInterface $request) use (&$writes) {
                $path = strtolower($request->getUri()->getPath());

                if (str_contains($path, 'users')) {
                    return $this->jsonResponse(['result' => ['data' => [['name' => 'jsmith']], 'total' => 1]]);
                }

                if ($request->getMethod() !== 'GET') {
                    $writes[] = (string) $request->getBody();

                    return $this->jsonResponse(['result' => ['name' => 'contact_1']]);
                }

                return $this->jsonResponse([
                    'result' => ['data' => [['name' => 'contact_1', 'title' => 'Same', 'user' => 'jsmith']], 'total' => 1],
                ]);
            },
        );
        ApiCommunicator::getInstance('https://ownerskip.daktela.test', 'test-token')->setHttpClient($http);

        $adapter = new DaktelaAdapter('https://ownerskip.daktela.test', 'test-token', 'test-db', new NullLogger());
        $result = $adapter->upsertContact(
            'name',
            Contact::fromArray(['name' => 'contact_1', 'title' => 'Same', 'user' => 'jsmith@acme.com']),
        );

        self::assertTrue($result->skipped, 'login must be compared against login');
        self::assertSame([], $writes, 'a skipped contact must not be written');
    }

    public function testAGenuinelyUnknownOwnerDoesNotMakeTheContactLookChangedForever(): void
    {
        // "No such user" is a final answer, so the owner is dropped from the
        // payload — and must be dropped from the comparison too, or the contact
        // differs on every run and is re-written forever.
        $writes = [];
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->method('send')->willReturnCallback(
            function (\Psr\Http\Message\RequestInterface $request) use (&$writes) {
                $path = strtolower($request->getUri()->getPath());

                if (str_contains($path, 'users')) {
                    // Answered, and there is no such user.
                    return $this->jsonResponse(['result' => ['data' => [], 'total' => 0]]);
                }

                if ($request->getMethod() !== 'GET') {
                    $writes[] = (string) $request->getBody();

                    return $this->jsonResponse(['result' => ['name' => 'contact_1']]);
                }

                return $this->jsonResponse([
                    'result' => ['data' => [['name' => 'contact_1', 'title' => 'Same']], 'total' => 1],
                ]);
            },
        );
        ApiCommunicator::getInstance('https://ownermissing.daktela.test', 'test-token')->setHttpClient($http);

        $adapter = new DaktelaAdapter('https://ownermissing.daktela.test', 'test-token', 'test-db', new NullLogger());
        $result = $adapter->upsertContact(
            'name',
            Contact::fromArray(['name' => 'contact_1', 'title' => 'Same', 'user' => 'ghost@acme.com']),
        );

        self::assertTrue($result->skipped, 'an unresolvable owner must not count as a change');
        self::assertSame([], $writes);
    }

    /** @param array<string, mixed> $payload */
    private function jsonResponse(array $payload): \GuzzleHttp\Psr7\Response
    {
        return new \GuzzleHttp\Psr7\Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
    }
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function unusableResponseProvider(): iterable
    {
        // The connector builds Response(null, 0, [], status) for ANY body without
        // a `result` key, so every one of these arrives with an EMPTY error array
        // and null data — hasErrors() is false and isEmpty() is true.
        yield 'success status, empty body' => [200, ''];
        yield 'success status, error envelope without result' => [200, '{"error":["Invalid filter field"]}'];
        yield 'server error, empty body' => [500, ''];
        yield 'gateway error, html interstitial' => [502, '{"message":"Bad Gateway"}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableResponseProvider')]
    public function testAnUnusableResponseFailsTheActivityEnumerationInsteadOfEndingIt(int $status, string $body): void
    {
        // Ending the generator cleanly here is silent data loss, not an empty
        // page: the batch reports 0 total / 0 failed / exhausted, so the caller
        // saves the watermark and every activity in the window is skipped forever.
        $adapter = $this->adapterWithHttpResponse($status, $body, __FUNCTION__ . $status);

        $this->expectException(AdapterException::class);
        iterator_to_array($adapter->iterateActivities(ActivityType::Call));
    }

    public function testAGenuinelyEmptyPageStillEndsTheEnumerationCleanly(): void
    {
        // The distinguishing detail: a real empty page carries `result.data: []`,
        // an ARRAY. Only a missing envelope yields null data. If this ever starts
        // throwing, the guard has become too strict and every exhausted drain
        // fails at its last page.
        $adapter = $this->adapterWithHttpResponse(200, '{"result":{"data":[],"total":0}}', __FUNCTION__);

        self::assertSame([], iterator_to_array($adapter->iterateActivities(ActivityType::Call)));
    }

    public function testAnUnusableUserLookupIsNotNegativeCachedAcrossContacts(): void
    {
        // The flag this protects exists because one blip used to strip the owner
        // from every contact behind it for the rest of the run. A result-less
        // response reports no errors, so reading it as "no such user" reinstates
        // exactly that bug — and worse: the cached null makes the owner change
        // invisible to hasChanges(), so the contact is reported Skipped, the
        // watermark advances, and its source timestamp never moves again.
        $userQueries = 0;
        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->method('send')->willReturnCallback(
            function (\Psr\Http\Message\RequestInterface $request) use (&$userQueries) {
                $path = strtolower($request->getUri()->getPath());
                if (str_contains($path, 'users')) {
                    $userQueries++;

                    return new \GuzzleHttp\Psr7\Response(200, [], ''); // no result envelope
                }

                return new \GuzzleHttp\Psr7\Response(200, [], json_encode([
                    'result' => ['data' => [['name' => 'contact_1', 'title' => 'Old', 'user' => 'someone']], 'total' => 1],
                ], JSON_THROW_ON_ERROR));
            },
        );
        ApiCommunicator::getInstance('https://usercache.daktela.test', 'test-token')->setHttpClient($http);

        $adapter = new DaktelaAdapter('https://usercache.daktela.test', 'test-token', 'test-db', new NullLogger());

        $first = $adapter->upsertContact('name', Contact::fromArray(['name' => 'c1', 'title' => 'New', 'user' => 'owner@acme.com']));
        $queriesAfterFirst = $userQueries;
        $adapter->upsertContact('name', Contact::fromArray(['name' => 'c2', 'title' => 'New', 'user' => 'owner@acme.com']));

        self::assertGreaterThan(0, $queriesAfterFirst, 'the first contact must attempt the lookup');
        self::assertGreaterThan(
            $queriesAfterFirst,
            $userQueries,
            'the failed lookup must be retried for the next contact, not served from a poisoned cache',
        );
        self::assertFalse($first->skipped, 'a contact whose owner could not be resolved must not be reported as up to date');
    }

    /**
     * A DaktelaAdapter whose HTTP layer returns one canned response. The
     * connector's ApiCommunicator is a singleton keyed on (url, token), so each
     * test needs its own $seed to avoid inheriting another test's client.
     */
    private function adapterWithHttpResponse(
        int $status,
        string $body,
        string $seed,
        bool $lookupFindsNothing = false,
    ): DaktelaAdapter {
        $url = 'https://' . strtolower(preg_replace('/[^a-z0-9]/i', '', $seed)) . '.daktela.test';

        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        // A fresh Response per call: a PSR-7 body is a stream, and handing back one
        // shared instance leaves the second read against an already-consumed body.
        $http->method('send')->willReturnCallback(
            function (\Psr\Http\Message\RequestInterface $request) use ($status, $body, $lookupFindsNothing) {
                // With this on, GETs answer "no such record" so the write path is
                // reached and the canned response applies to the write itself.
                if ($lookupFindsNothing && $request->getMethod() === 'GET') {
                    return new \GuzzleHttp\Psr7\Response(200, [], '{"result":{"data":[],"total":0}}');
                }

                return new \GuzzleHttp\Psr7\Response($status, [], $body);
            },
        );
        ApiCommunicator::getInstance($url, 'test-token')->setHttpClient($http);

        return new DaktelaAdapter($url, 'test-token', 'test-db', new NullLogger());
    }
}
