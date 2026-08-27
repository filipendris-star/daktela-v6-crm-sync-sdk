<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\BatchSync;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Batch activity-export protections against silent data loss and corruption.
 *
 * The first two lived in BatchSyncLedgerTest and never used a ledger — they were
 * residents of that file, not tests of it, and deleting the file with the feature
 * took them along. Mutation testing showed the cost: the per-type pagination key
 * could be collapsed to one shared offset, and the empty-payload guard deleted
 * outright, with the whole suite staying green.
 *
 * The rest cover the lookup_field checks: a payload that carries no value to
 * dedupe by, and two activities that collapse onto one CRM record.
 */
final class BatchSyncActivityExportTest extends TestCase
{
    public function testATypeWithNoApplicableRulesAbortsInsteadOfCreatingABlankRecord(): void
    {
        // A mapping file that declares only `types:` (the loader tolerates an
        // absent `default:`) leaves the base rule set empty, so an activity type
        // missing from that map maps to []. Writing that payload creates a blank
        // CRM record.
        //
        // It aborts rather than failing the record: the mapping is wrong for
        // every activity of the type, and a per-record failure in a mixed batch
        // is a partial failure, which advances the watermark past exactly the
        // records it refused. See testAMappingThatCannotDedupeHoldsTheWatermark…
        // in SyncEngineTest for the watermark half of the same argument.
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activityOfType('sms-1', ActivityType::Sms),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('createActivity');
        $crmAdapter->expects(self::never())->method('upsertActivity');

        $typesOnly = new MappingCollection('activity', 'external_id', [], [
            'call' => [new FieldMapping('title', 'subject')],
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/empty CRM payload/');
        $this->batchSync($ccAdapter, $crmAdapter, $typesOnly)->syncActivities([ActivityType::Sms]);
    }

    public function testMultiTypeBatchBoundaryReadsEveryActivityExactlyOnce(): void
    {
        // One shared offset across activity types fed every type's query, so when
        // an earlier type contributed rows to a batch, the next batch skipped the
        // remaining type's unread rows — silent loss at every batch boundary.
        $perType = [
            'call' => [$this->activity('call-1')],
            'email' => [
                $this->activityOfType('email-1', ActivityType::Email),
                $this->activityOfType('email-2', ActivityType::Email),
                $this->activityOfType('email-3', ActivityType::Email),
            ],
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturnCallback(
            function (ActivityType $type, ?\DateTimeImmutable $since = null, int $offset = 0) use ($perType): \Generator {
                yield from array_slice($perType[$type->value], $offset);
            },
        );

        $exported = [];
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturnCallback(
            function (string $lookupField, Activity $activity) use (&$exported): Activity {
                $exported[] = $activity->get('external_id');

                return Activity::fromArray(['id' => 'crm-' . count($exported)]);
            },
        );

        $batchSync = $this->batchSyncForTypes($ccAdapter, $crmAdapter, [ActivityType::Call, ActivityType::Email], batchSize: 2);

        $batches = 0;
        do {
            $result = $batchSync->syncActivities([ActivityType::Call, ActivityType::Email]);
            self::assertLessThan(10, ++$batches, 'drain must terminate');
        } while (!$result->isExhausted());

        sort($exported);
        self::assertSame(
            ['ref-call-1', 'ref-email-1', 'ref-email-2', 'ref-email-3'],
            $exported,
            'every activity of every type read exactly once',
        );
    }

    /**
     * The collision check spans the whole drain, not one activity type.
     *
     * `syncActivities()` walks every configured type in one call, so two
     * activities of DIFFERENT types can share a lookup value and collapse onto
     * one CRM record just as easily as two of the same type. Scoping the memory
     * per type left that undetected with the suite green.
     */
    public function testACollisionAcrossActivityTypesIsDetected(): void
    {
        // Both map to external_id = "shared-ref" despite being different records.
        $call = Activity::fromArray(['id' => 'call-1', 'name' => 'shared-ref', 'title' => 'A call']);
        $call->setActivityType(ActivityType::Call);
        $email = Activity::fromArray(['id' => 'email-1', 'name' => 'shared-ref', 'title' => 'An email']);
        $email->setActivityType(ActivityType::Email);

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturnCallback(
            fn (ActivityType $t): \Generator => yield from ($t === ActivityType::Call ? [$call] : [$email]),
        );

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-1']));

        $batchSync = $this->batchSyncForTypes(
            $ccAdapter,
            $crmAdapter,
            [ActivityType::Call, ActivityType::Email],
            batchSize: 100,
        );

        try {
            $batchSync->syncActivities([ActivityType::Call, ActivityType::Email]);
            self::fail('two activities sharing a lookup value must abort, whatever their types');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('shared-ref', $e->getMessage());
            self::assertStringContainsString('call-1', $e->getMessage());
            self::assertStringContainsString('email-1', $e->getMessage());
        }
    }

    /**
     * `name` is deliberately NOT equal to `id`.
     *
     * The mapping carries `name` into the CRM as the lookup value, while the
     * collision check remembers which CC `id` used it. If the fixture made them
     * the same string the check would be comparing a value against itself, and a
     * mutation that remembered the lookup value instead of the CC id would
     * survive — which it did until this fixture was changed.
     */
    private function activity(string $id): Activity
    {
        $activity = Activity::fromArray([
            'id' => $id,
            'name' => 'ref-' . $id,
            'title' => 'Call ' . $id,
        ]);
        $activity->setActivityType(ActivityType::Call);

        return $activity;
    }

    /** @param array<int, Activity> $items */
    private function gen(array $items): \Generator
    {
        yield from $items;
    }

    /** Same `name` !== `id` rule as activity(); see its docblock. */
    private function activityOfType(string $id, ActivityType $type): Activity
    {
        $activity = Activity::fromArray(['id' => $id, 'name' => 'ref-' . $id, 'title' => 'Act ' . $id]);
        $activity->setActivityType($type);

        return $activity;
    }

    /** @param ActivityType[] $types */
    private function batchSyncForTypes(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        array $types,
        int $batchSize,
    ): BatchSync {
        $activityMapping = new MappingCollection('activity', 'external_id', [
            new FieldMapping('name', 'external_id'),
            new FieldMapping('title', 'subject'),
        ]);

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: $batchSize,
            entities: [
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', $types),
            ],
            mappings: [
                'activity' => $activityMapping,
            ],
        );

        return new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
        );
    }

    /**
     * The export must refuse a record it cannot dedupe.
     *
     * upsertActivity() is the only duplicate protection, and it is driven by the
     * mapping's lookup_field. If the mapped CRM payload carries no value there,
     * the adapter has nothing to look up and creates on every run — silently,
     * because "found nothing then created" and "created" are indistinguishable
     * downstream.
     *
     * Each case below is a way of getting that wrong that a config-load check
     * could not see, which is why the check lives at the point of the write.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('undedupableMappings')]
    public function testAnActivityWithNoLookupValueAbortsInsteadOfDuplicating(MappingCollection $mapping, string $why): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$this->activity('call-1')]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('upsertActivity');
        $crmAdapter->expects(self::never())->method('createActivity');

        // Aborts the step, not the record: the mapping is wrong for every activity,
        // and a per-record failure in a mixed batch advances the watermark past
        // the refused records (see SyncEngineTest's mixed-batch watermark test).
        try {
            $this->batchSync($ccAdapter, $crmAdapter, $mapping)->syncActivities([ActivityType::Call]);
            self::fail('expected the step to abort: ' . $why);
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('cannot dedupe', $e->getMessage(), $why);
        }
    }

    /**
     * A lookup value that does not vary per record is silent DELETION, not
     * duplication, and must abort the step.
     *
     * A static rule — or a `default_value` transformer firing because the source
     * field is absent — yields the same value for every activity. It passes the
     * non-empty check, and then upsertActivity() resolves every activity to the
     * SAME CRM record, each overwriting the last. The run reports 0 failed.
     *
     * Non-emptiness cannot see this. A collision inside one batch proves it,
     * without having to guess whether a rule is "constant enough".
     */
    public function testActivitiesSharingOneLookupValueAbortTheStep(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activity('call-1'),
            $this->activity('call-2'),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-1']));

        $constant = new MappingCollection('activity', 'external_id', [
            new FieldMapping('absent_field', 'external_id', staticValue: 'DAKTELA-CONST', hasStaticValue: true),
            new FieldMapping('title', 'subject'),
        ]);

        try {
            $this->batchSync($ccAdapter, $crmAdapter, $constant)->syncActivities([ActivityType::Call]);
            self::fail('two activities sharing a lookup value must abort the step');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('both map to lookup_field', $e->getMessage());
            self::assertStringContainsString('DAKTELA-CONST', $e->getMessage());
        }
    }

    /**
     * The same activity re-read inside one drain is NOT a collision — the offset
     * overlap after a retry is normal, and refusing it would break resumption.
     */
    public function testTheSameActivityTwiceInABatchIsNotTreatedAsACollision(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            $this->activity('call-1'),
            $this->activity('call-1'),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-1']));

        $result = $this->batchSync($ccAdapter, $crmAdapter)->syncActivities([ActivityType::Call]);

        self::assertSame(0, $result->getFailedCount());
    }

    /**
     * The batch path's reported verdict and target id.
     *
     * The CHANGELOG lists the Created→Updated flip as a SILENT breaking change
     * ("getCreatedCount() for activities goes from N to 0"), and nothing held
     * it: flipping the status back, or dropping targetId, left the suite green.
     * The webhook twin is pinned by testSyncActivitySuccess; this is the batch
     * half.
     */
    public function testTheBatchPathReportsUpdatedAndNamesTheCrmRecord(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$this->activity('call-1')]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->method('upsertActivity')->willReturn(Activity::fromArray(['id' => 'crm-9']));

        $record = $this->batchSync($ccAdapter, $crmAdapter)->syncActivities([ActivityType::Call])->getRecords()[0];

        self::assertSame(SyncStatus::Updated, $record->status, 'upsert does not say which branch it took');
        self::assertSame('crm-9', $record->targetId);
    }

    /**
     * An EMPTY lookup value is as unusable as an absent one — the adapter would
     * search for '' and create every time. Reachable whenever the source field
     * is present but blank, which the null case does not cover.
     */
    public function testAnEmptyLookupValueIsRefused(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([
            Activity::fromArray(['id' => 'call-1', 'name' => '', 'activity_type' => 'call', 'title' => 'T']),
        ]));

        $crmAdapter = $this->createMock(CrmAdapterInterface::class);
        $crmAdapter->expects(self::never())->method('upsertActivity');

        $this->expectException(ConfigurationException::class);
        $this->batchSync($ccAdapter, $crmAdapter)->syncActivities([ActivityType::Call]);
    }

    /**
     * The check must read the object the ADAPTER receives.
     *
     * SupportsDealLinkingInterface documents its return as "the same or an
     * augmented activity instance", so an adapter may legally hand back a fresh
     * one. Checking the mapped array instead let the guard pass while
     * upsertActivity() got null at lookup_field — the precise condition the
     * guard exists to prevent, on a run reporting zero failures.
     */
    public function testALinkDealAdapterThatDropsTheLookupFieldIsCaught(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $ccAdapter->method('iterateActivities')->willReturn($this->gen([$this->activity('call-1')]));

        $crmAdapter = new DealLinkingCrmAdapterThatDropsTheLookupField();

        try {
            $this->batchSync($ccAdapter, $crmAdapter, null, 'deal_id')->syncActivities([ActivityType::Call]);
            self::fail('the export must refuse a payload the adapter cannot dedupe');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('cannot dedupe', $e->getMessage());
        }

        self::assertNull($crmAdapter->sawLookupValue, 'the adapter must never have been called');
    }

    /** @return iterable<string, array{MappingCollection, string}> */
    public static function undedupableMappings(): iterable
    {
        yield 'lookup_field names a cc_field, not what the mapping writes' => [
            new MappingCollection('activity', 'name', [new FieldMapping('name', 'external_id')]),
            'the shape the SDK\'s own example, quickstart and fixtures shipped',
        ];

        yield 'lookup_field written only by a type that does not apply here' => [
            new MappingCollection('activity', 'crm_ref', [new FieldMapping('title', 'subject')], [
                'email' => [new FieldMapping('name', 'crm_ref')],
            ]),
            'forType(call) yields base rules only, so crm_ref is absent',
        ];

        yield 'dotted lookup_field Activity::get() cannot resolve' => [
            new MappingCollection('activity', 'nested.external_id', [new FieldMapping('name', 'nested.external_id')]),
            'the mapper writes it nested; the flat get() returns null',
        ];

        yield 'lookup value is an array' => [
            new MappingCollection('activity', 'external_id', [
                new FieldMapping('name', 'external_id', append: true),
                new FieldMapping('title', 'external_id', append: true),
            ]),
            'an append rule yields a list, which no CRM can look up',
        ];
    }

    private function batchSync(
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
        ?MappingCollection $mappingOverride = null,
        ?string $linkDeal = null,
    ): BatchSync {
        $activityMapping = $mappingOverride ?? new MappingCollection('activity', 'external_id', [
            new FieldMapping('name', 'external_id'),
            new FieldMapping('title', 'subject'),
        ]);

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call], linkDeal: $linkDeal),
            ],
            mappings: [
                'activity' => $activityMapping,
            ],
        );

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
        );

        return $batchSync;
    }
}

/**
 * A deal-linking adapter that returns a FRESH activity, without carrying the
 * lookup field across. Legal per SupportsDealLinkingInterface ("the same or an
 * augmented activity instance"), and the shape that proves the export checks
 * the object it is about to hand over rather than the array it mapped earlier.
 */
final class DealLinkingCrmAdapterThatDropsTheLookupField extends \Daktela\CrmSync\Tests\Support\NullCrmAdapter implements \Daktela\CrmSync\Adapter\SupportsDealLinkingInterface
{
    public mixed $sawLookupValue = null;

    public function linkActivityToDeal(Activity $activity, string $dealField): Activity
    {
        return Activity::fromArray(['subject' => $activity->get('subject')]);
    }

    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $this->sawLookupValue = $activity->get($lookupField);

        return Activity::fromArray(['id' => 'crm-1']);
    }
}
