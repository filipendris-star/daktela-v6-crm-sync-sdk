<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Adapter;

use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Tests\Support\NullCrmAdapter;
use PHPUnit\Framework\TestCase;

/**
 * The worked example in docs/04, "Deriving a Value From Two Daktela Fields",
 * executed.
 *
 * The SDK stopped deriving a call-state token in 1.2.0: its vocabulary was chosen
 * to feed one CRM's value_map, and what a combination of Daktela fields MEANS to a
 * given CRM is that CRM's business logic. The capability did not go away — it moved
 * to where it belongs. This test is the proof that it still works, and the thing
 * that fails if the documented recipe stops being true.
 *
 * Keep the adapter below in sync with the docs snippet. If you change one, change
 * the other.
 */
final class DerivedActivityStateExampleTest extends TestCase
{
    /**
     * Step 1 of the recipe: the mapping passes BOTH source fields through, so the
     * adapter has the inputs it needs. Nothing here derives anything.
     */
    public function testTheMappingPassesBothSourceFieldsToTheAdapter(): void
    {
        $mapper = new FieldMapper(TransformerRegistry::withDefaults());
        $mapping = new MappingCollection('activity', 'external_id', [], [
            'call' => [
                new FieldMapping('name', 'external_id'),
                new FieldMapping('item_direction', '_direction'),
                new FieldMapping('item_answered', '_answered'),
            ],
        ]);

        $mapped = $mapper->map(
            Activity::fromArray([
                'name' => 'call-1',
                'item_direction' => 'IN',
                'item_answered' => '0',
            ]),
            $mapping->forType('call'),
            SyncDirection::CcToCrm,
        );

        self::assertSame('IN', $mapped['_direction'], 'casing reaches the adapter unchanged');
        self::assertSame('0', $mapped['_answered'], 'the string serialisation reaches it too');
    }

    /**
     * @return iterable<string, array{string, mixed, string, int}>
     *   [direction, answered, expected state, expected `done`]
     */
    public static function callStateProvider(): iterable
    {
        yield 'inbound answered' => ['in', 1, 'in_answered', 1];
        yield 'inbound missed' => ['in', 0, 'in_missed', 0];
        yield 'outbound answered' => ['out', 1, 'out_answered', 1];
        yield 'outbound no answer' => ['out', 0, 'out_noanswer', 1];
        yield 'internal answered' => ['internal', 1, 'internal_answered', 1];
        yield 'internal no answer' => ['internal', 0, 'internal_noanswer', 1];

        // The v6 API serialises the flags as strings on some endpoints. "0" must
        // not read as answered — empty("0") is true in PHP, which is why the
        // recipe uses empty() rather than a cast.
        yield 'string flags' => ['out', '0', 'out_noanswer', 1];
        yield 'string one' => ['in', '1', 'in_answered', 1];

        // The chat family (web, fbm, wap, vbr, igdm) stores the direction
        // UPPERCASE while calls and emails store it lowercase. Unfolded, every
        // chat misses both arms and lands in the internal_* default.
        yield 'uppercase chat inbound' => ['IN', 1, 'in_answered', 1];
        yield 'uppercase chat missed' => ['IN', 0, 'in_missed', 0];
        yield 'mixed case' => ['Out', 0, 'out_noanswer', 1];

        // An item type carrying no direction is not an internal call.
        yield 'no direction at all' => ['', 1, 'unknown', 1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('callStateProvider')]
    public function testTheAdapterDerivesTheStateAndTheCrmFlag(
        string $direction,
        mixed $answered,
        string $expectedState,
        int $expectedDone,
    ): void {
        $adapter = new ExampleDerivingCrmAdapter();

        $adapter->upsertActivity('external_id', Activity::fromArray([
            'external_id' => 'call-1',
            'subject' => 'Call',
            '_direction' => $direction,
            '_answered' => $answered,
        ]));

        self::assertSame($expectedState, $adapter->lastState);
        self::assertSame($expectedDone, $adapter->lastPayload['done'] ?? null);
    }

    public function testTheDerivationInputsNeverReachTheCrm(): void
    {
        // The underscore-prefixed fields are internal plumbing. Left in the
        // payload they are rejected as unknown fields by a strict CRM, or stored
        // as junk by a lenient one.
        $adapter = new ExampleDerivingCrmAdapter();

        $adapter->upsertActivity('external_id', Activity::fromArray([
            'external_id' => 'call-1',
            '_direction' => 'in',
            '_answered' => 1,
        ]));

        self::assertArrayNotHasKey('_direction', $adapter->lastPayload);
        self::assertArrayNotHasKey('_answered', $adapter->lastPayload);
        self::assertArrayHasKey('external_id', $adapter->lastPayload, 'real fields survive');
    }

    public function testAMissedCallIsLabelledForTheAgentReadingTheCrm(): void
    {
        $adapter = new ExampleDerivingCrmAdapter();

        $adapter->upsertActivity('external_id', Activity::fromArray([
            'external_id' => 'call-1',
            'subject' => 'Support line',
            '_direction' => 'in',
            '_answered' => 0,
        ]));

        self::assertSame('Missed call — Support line', $adapter->lastPayload['subject']);
        self::assertSame('call', $adapter->lastPayload['type']);
    }

    public function testAnOutboundCallIsTypedAsOutgoing(): void
    {
        $adapter = new ExampleDerivingCrmAdapter();

        $adapter->upsertActivity('external_id', Activity::fromArray([
            'external_id' => 'call-1',
            '_direction' => 'OUT',
            '_answered' => 1,
        ]));

        self::assertSame('outgoing_call', $adapter->lastPayload['type']);
    }
}

/**
 * The docs/04 snippet, verbatim in behaviour.
 *
 * A real adapter would POST the payload; this one records it so the derivation
 * can be asserted without an HTTP layer — which is also how you would test your
 * own adapter's derivation.
 */
final class ExampleDerivingCrmAdapter extends NullCrmAdapter
{
    /** @var array<string, mixed> */
    public array $lastPayload = [];

    public string $lastState = '';

    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $payload = $activity->toArray();

        // Case-fold: 'in'/'out' for calls and emails, 'IN'/'OUT' for chats.
        $direction = strtolower((string) ($payload['_direction'] ?? ''));

        // empty() reads 0, "0", null and "" alike as not-answered.
        $answered = !empty($payload['_answered']);

        $state = match ($direction) {
            'in' => $answered ? 'in_answered' : 'in_missed',
            'out' => $answered ? 'out_answered' : 'out_noanswer',
            '' => 'unknown',
            default => $answered ? 'internal_answered' : 'internal_noanswer',
        };

        // The CRM's own vocabulary — this integration's policy, not the SDK's.
        $payload['done'] = $state === 'in_missed' ? 0 : 1;
        $payload['type'] = $direction === 'out' ? 'outgoing_call' : 'call';
        if ($state === 'in_missed') {
            $payload['subject'] = 'Missed call — ' . ($payload['subject'] ?? '');
        }

        unset($payload['_direction'], $payload['_answered']);

        $this->lastState = $state;
        $this->lastPayload = $payload;

        return Activity::fromArray($payload);
    }
}
