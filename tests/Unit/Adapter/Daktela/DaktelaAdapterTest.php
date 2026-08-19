<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Adapter\Daktela;

use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Entity\ActivityType;
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

    #[\PHPUnit\Framework\Attributes\DataProvider('callStateProvider')]
    public function testFlattenActivityRowDerivesCallState(array $item, ?string $expected): void
    {
        $adapter = new DaktelaAdapter(
            'https://test.daktela.com',
            'test-token',
            'test-db',
            new NullLogger(),
        );

        $method = new \ReflectionMethod($adapter, 'flattenActivityRow');
        $row = $method->invoke($adapter, ['item' => $item]);

        self::assertSame($expected, $row['item_call_state'] ?? null);
    }

    /** @return iterable<string, array{array<string, mixed>, ?string}> */
    public static function callStateProvider(): iterable
    {
        yield 'outgoing answered' => [['direction' => 'out', 'answered' => 1], 'out_answered'];
        yield 'outgoing no answer' => [['direction' => 'out', 'answered' => 0], 'out_noanswer'];
        yield 'incoming answered' => [['direction' => 'in', 'answered' => 1], 'in_answered'];
        yield 'incoming missed' => [['direction' => 'in', 'answered' => 0], 'in_missed'];
        yield 'internal answered' => [['direction' => 'internal', 'answered' => 1], 'internal_answered'];
        yield 'internal no answer' => [['direction' => 'internal', 'answered' => 0], 'internal_noanswer'];

        // The v6 API serialises the flags as strings too — "0" must not read as answered.
        yield 'string flags' => [['direction' => 'out', 'answered' => '0'], 'out_noanswer'];

        // SMS items carry a direction but no answered flag: no state must be derived,
        // so per-type sms mappings never see a bogus item_call_state.
        yield 'sms-like item without answered' => [['direction' => 'in', 'text' => 'hi'], null];

        yield 'item without direction' => [['answered' => 1], null];
    }
}
