<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Adapter\Daktela;

use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Exception\AdapterException;
use Daktela\DaktelaV6\Http\ApiCommunicator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The adapter's write and read safety rules.
 *
 * All of these guard the same failure: a response that did not actually say what
 * the caller assumes it said. Read as success, an ambiguous response makes the
 * engine report a record synced and advance the watermark past it — and because
 * the source timestamp never moves again, only a forced full sync ever brings it
 * back. So every ambiguous shape must fail loudly instead.
 *
 * The Daktela connector's ApiCommunicator is a singleton keyed on (url, token),
 * so each test uses its own host to avoid inheriting another test's HTTP client.
 */
final class DaktelaAdapterWriteSafetyTest extends TestCase
{
    // ── account upsert ───────────────────────────────────────────────────────

    public function testAnAccountUpsertWithNoLookupValueIsRefused(): void
    {
        $adapter = $this->adapter(__FUNCTION__, static fn () => null);

        $this->expectException(AdapterException::class);
        $adapter->upsertAccount('name', Account::fromArray(['title' => 'Acme']));
    }

    public function testAnUnchangedAccountIsSkipped(): void
    {
        $writes = 0;
        $adapter = $this->adapter(__FUNCTION__, function ($request) use (&$writes) {
            if ($request->getMethod() !== 'GET') {
                $writes++;

                return $this->json(['result' => ['name' => 'acc_1']]);
            }

            return $this->json(['result' => ['data' => [['name' => 'acc_1', 'title' => 'Acme']], 'total' => 1]]);
        });

        $result = $adapter->upsertAccount('name', Account::fromArray(['name' => 'acc_1', 'title' => 'Acme']));

        self::assertTrue($result->skipped);
        self::assertSame(0, $writes, 'a skipped account must not be written');
    }

    public function testAChangedAccountIsUpdatedNotCreated(): void
    {
        $adapter = $this->adapter(__FUNCTION__, function ($request) {
            if ($request->getMethod() !== 'GET') {
                return $this->json(['result' => ['name' => 'acc_1', 'title' => 'Acme Ltd']]);
            }

            return $this->json(['result' => ['data' => [['name' => 'acc_1', 'title' => 'Acme']], 'total' => 1]]);
        });

        $result = $adapter->upsertAccount('name', Account::fromArray(['name' => 'acc_1', 'title' => 'Acme Ltd']));

        self::assertFalse($result->created, 'an existing account must be updated, not re-created');
        self::assertFalse($result->skipped);
    }

    public function testAnAbsentAccountIsCreated(): void
    {
        $adapter = $this->adapter(__FUNCTION__, function ($request) {
            if ($request->getMethod() !== 'GET') {
                return $this->json(['result' => ['name' => 'acc_new']]);
            }

            return $this->json(['result' => ['data' => [], 'total' => 0]]);
        });

        $result = $adapter->upsertAccount('name', Account::fromArray(['name' => 'acc_new', 'title' => 'New']));

        self::assertTrue($result->created);
        self::assertSame('acc_new', $result->entity->getId());
    }

    // ── ambiguous responses must not read as success ─────────────────────────

    /** @return iterable<string, array{string}> */
    public static function unusableCreateResponseProvider(): iterable
    {
        // A body with no `result` key at all — an empty 2xx, a proxy error page.
        // The connector turns it into null data, which survives isSuccess().
        yield 'no result envelope' => [''];

        // `result: true` casts to an array carrying no name, so the created
        // record cannot be identified.
        yield 'bare result: true' => ['{"result":true}'];

        // Named nothing.
        yield 'empty name' => ['{"result":{"name":""}}'];
    }

    /**
     * A create must come back NAMING the record. Returning ['id' => null] reported
     * the record Created with a null target while the write's outcome was in fact
     * unknown — and let the watermark advance over it.
     *
     * Failing is safe to retry: upsert looks the record up first, so if the create
     * did land, the retry updates it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableCreateResponseProvider')]
    public function testACreateThatNamesNoRecordFails(string $body): void
    {
        $adapter = $this->adapter(
            __FUNCTION__ . md5($body),
            fn ($request) => $request->getMethod() === 'GET'
                ? $this->json(['result' => ['data' => [], 'total' => 0]])
                : new \GuzzleHttp\Psr7\Response(200, [], $body),
        );

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessageMatches('/named no created record/');

        $adapter->createAccount(Account::fromArray(['name' => 'acc_1', 'title' => 'Acme']));
    }

    public function testAnUpdateWithNoResponseBodyFails(): void
    {
        // An update already knows WHICH record it wrote, so a response confirming
        // it is enough — but a body with no record at all affirms nothing, and
        // returning the id we were handed reported garbage as a successful write.
        $adapter = $this->adapter(
            __FUNCTION__,
            fn ($request) => $request->getMethod() === 'GET'
                ? $this->json(['result' => ['data' => [], 'total' => 0]])
                : new \GuzzleHttp\Psr7\Response(200, [], ''),
        );

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessageMatches('/carried no record/');

        $adapter->updateAccount('acc_1', Account::fromArray(['name' => 'acc_1', 'title' => 'Acme']));
    }

    public function testAnErrorEnvelopeOnA200IsStillAFailure(): void
    {
        // The v6 API reports some failures as a 200 carrying an error list.
        $adapter = $this->adapter(
            __FUNCTION__,
            fn ($request) => $request->getMethod() === 'GET'
                ? $this->json(['result' => ['data' => [], 'total' => 0]])
                : $this->json(['error' => ['title is required'], 'result' => null]),
        );

        $this->expectException(AdapterException::class);
        $adapter->createAccount(Account::fromArray(['name' => 'acc_1']));
    }

    /**
     * A read that could not be performed must throw, never read as "not found".
     * Read as absence, upsert creates a SECOND record for one that already exists
     * and reports it Created.
     */
    public function testAResultLessReadIsAFailureNotAnAbsence(): void
    {
        $adapter = $this->adapter(__FUNCTION__, fn () => new \GuzzleHttp\Psr7\Response(200, [], ''));

        $this->expectException(AdapterException::class);
        $adapter->findAccountBy(['name' => 'acc_1']);
    }

    public function testAnActivityEnumerationStopsOnAShortPage(): void
    {
        // The offset drain ends when a page comes back shorter than the page size;
        // without that it would loop forever on a CRM that keeps returning rows.
        $pages = 0;
        $adapter = $this->adapter(__FUNCTION__, function () use (&$pages) {
            $pages++;

            return $this->json(['result' => [
                'data' => [['name' => 'act_1', 'title' => 'Call', 'item' => ['direction' => 'in']]],
                'total' => 1,
            ]]);
        });

        $activities = iterator_to_array($adapter->iterateActivities(ActivityType::Call));

        self::assertCount(1, $activities);
        self::assertSame(1, $pages, 'a short page ends the drain');
        self::assertSame('in', $activities[0]->get('item_direction'), 'item fields are flattened for mapping');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function adapter(string $seed, callable $handler): DaktelaAdapter
    {
        $host = 'https://' . substr(md5($seed), 0, 12) . '.daktela.test';

        $http = $this->createMock(\GuzzleHttp\ClientInterface::class);
        $http->method('send')->willReturnCallback($handler);
        ApiCommunicator::getInstance($host, 'test-token')->setHttpClient($http);

        return new DaktelaAdapter($host, 'test-token', 'test-db', new NullLogger());
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): \GuzzleHttp\Psr7\Response
    {
        return new \GuzzleHttp\Psr7\Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
