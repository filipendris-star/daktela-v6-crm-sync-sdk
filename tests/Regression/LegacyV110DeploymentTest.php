<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Regression;

use Daktela\CrmSync\Adapter\SupportsCursorPaginationInterface;
use Daktela\CrmSync\Adapter\SupportsDealLinkingInterface;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncEngine;
use Daktela\CrmSync\Sync\SyncEngineFactory;
use Daktela\CrmSync\Tests\Integration\Fakes\FakeCcAdapter;
use Daktela\CrmSync\Tests\Integration\Fakes\FakeCrmAdapter;
use Daktela\CrmSync\Tests\Support\CapturingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Does a deployment written against v1.1.0 still run on this release?
 *
 * 1.2.0 is a MINOR, so the answer has to be yes for everything except changes we
 * have consciously decided to charge an upgrade for. This suite is where that
 * promise is enforced rather than asserted: it drives the v1.1.0 example config
 * VERBATIM (tests/Fixtures/legacy-v1.1.0/, a frozen copy of the tag) through the
 * loader, the factory and a full sync.
 *
 * The one deliberate migration is `lookup_field`. The 1.1.0 example shipped
 * `lookup_field: name` — a cc_field — against `crm_field: external_id`, so the
 * export could never find the record it had written and created a duplicate CRM
 * activity on every run. That is silent data corruption, and the fix cannot be
 * automatic (only the operator knows which CRM field carries the Daktela id). So
 * it is refused, loudly, with the correct value named — and, critically, WITHOUT
 * taking contacts and accounts down with it.
 *
 * If a change makes any test here fail, that change breaks live deployments.
 */
final class LegacyV110DeploymentTest extends TestCase
{
    private const LEGACY_CONFIG = __DIR__ . '/../Fixtures/legacy-v1.1.0/sync.yaml';

    /**
     * The same 1.1.0 config with a CORRECT `lookup_field`. Not everyone copied the
     * example's mistake, and this is the only fixture that reaches the upgrade path
     * for a working activity export — in LEGACY_CONFIG the entity is faulted and
     * disabled, so the state-store guard and the seeding rail are both unreachable.
     */
    private const LEGACY_CONFIG_WORKING_EXPORT = __DIR__ . '/../Fixtures/legacy-v1.1.0-correct/sync.yaml';

    public function testTheV110ExampleConfigStillLoads(): void
    {
        $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG);

        self::assertSame('https://your-instance.daktela.com', $config->instanceUrl);
        self::assertSame(100, $config->batchSize);
    }

    /**
     * The whole point of faulting per entity instead of aborting the load: the
     * activity mapping in this config is unusable, and contacts and accounts must
     * keep syncing anyway.
     *
     * This regressed once already — the 1.2.0 lookup_field check was added inside
     * the loader's mapping read, so one bad activity mapping stopped the entire
     * config from loading.
     */
    public function testContactsAndAccountsStillSyncDespiteTheLegacyActivityMapping(): void
    {
        $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG);

        self::assertTrue($config->isEntityEnabled('contact'));
        self::assertTrue($config->isEntityEnabled('account'));
        self::assertNotNull($config->getMapping('contact'));
        self::assertNotNull($config->getMapping('account'));
    }

    public function testTheLegacyActivityMappingIsRefusedAndNamesTheFix(): void
    {
        $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG);

        $fault = $config->getEntityFaults()['activity'] ?? '';
        self::assertStringContainsString('names a cc_field', $fault);
        self::assertStringContainsString('Did you mean "external_id"?', $fault, 'must name the value to use');
        self::assertFalse($config->isEntityEnabled('activity'), 'it must not run half-configured');
    }

    /**
     * `SyncEngineFactory::fromYaml($path, $adapter)` — two arguments, no state
     * store — is the call every 1.1.0 example and doc used. It must still build.
     *
     * It briefly did not: `initial_sync` is new in 1.2.0, it defaulted to "now",
     * and "now" requires a state store — so a key no pre-1.2.0 config could
     * mention silently imposed a new hard requirement and took the sync down at
     * startup.
     */
    public function testTheTwoArgumentFactoryCallStillBuilds(): void
    {
        $factory = SyncEngineFactory::fromYaml(
            self::LEGACY_CONFIG,
            new FakeCrmAdapter(),
            new NullLogger(),
        );

        self::assertInstanceOf(SyncEngineFactory::class, $factory);
    }

    /**
     * The case that actually exercises the state-store guard: an ENABLED activity
     * export, on a config that never mentions `initial_sync`. This is the shape
     * that broke — the factory refused to build and the whole sync stopped at
     * startup.
     */
    public function testTheTwoArgumentFactoryCallBuildsForAWorkingLegacyExport(): void
    {
        $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG_WORKING_EXPORT);
        self::assertTrue($config->isEntityEnabled('activity'), 'the guard is only live for an enabled export');
        self::assertSame([], $config->getEntityFaults());

        $factory = SyncEngineFactory::fromYaml(
            self::LEGACY_CONFIG_WORKING_EXPORT,
            new FakeCrmAdapter(),
            new NullLogger(),
        );

        self::assertInstanceOf(SyncEngineFactory::class, $factory);
    }

    /**
     * With no state store and no `initial_sync`, the export keeps the pre-1.2.0
     * behaviour and says so. It must warn — every run re-reads the full history —
     * but it must not refuse.
     */
    public function testAWorkingLegacyExportWarnsInsteadOfRefusing(): void
    {
        $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG_WORKING_EXPORT);
        $logger = new CapturingLogger();

        $result = (new SyncEngine(new FakeCcAdapter(), new FakeCrmAdapter(), $config, $logger))->fullSync();

        self::assertArrayNotHasKey('activity', $result->stepFailures, 'a legacy export must not be refused');

        $warnings = implode("\n", $logger->messagesAt('warning'));
        self::assertStringContainsString('initial_sync', $warnings, 'the operator must be told to set it');
        self::assertStringContainsString('2.0', $warnings, 'and when the default changes');
    }

    /**
     * The seeding rail must stay unreachable for a config that never opted into it,
     * even on a genuine first run with a state store present. Seeding here would
     * write "now" over an empty watermark and silently drop every activity older
     * than the upgrade.
     */
    public function testAFirstRunOfALegacyExportDoesNotSeedTheWatermarkAway(): void
    {
        $statePath = sys_get_temp_dir() . '/legacy-seed-' . bin2hex(random_bytes(6)) . '.json';
        $store = new FileSyncStateStore($statePath);

        try {
            $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG_WORKING_EXPORT);
            self::assertNull($store->getLastSyncTime('activity'), 'precondition: no watermark');

            $cc = new FakeCcAdapter();
            (new SyncEngine($cc, new FakeCrmAdapter(), $config, new NullLogger(), stateStore: $store))->fullSync();

            self::assertNotEmpty(
                $cc->activityQueries,
                'a legacy export must actually read history on its first run, not seed-and-skip',
            );
        } finally {
            @unlink($statePath);
        }
    }

    public function testAnAbsentInitialSyncKeepsThePre120Behaviour(): void
    {
        $activity = (new YamlConfigLoader())->load(self::LEGACY_CONFIG)->getEntityConfig('activity');

        self::assertNotNull($activity);
        self::assertNull($activity->initialSync, 'absent must never read as an explicit choice');
        self::assertSame('everything', $activity->effectiveInitialSync());
    }

    /**
     * A full sync over the legacy config: contacts and accounts flow, and the
     * activity fault is reported rather than swallowed — a scheduler gating on
     * hasStepFailures() sees a non-clean run and the operator gets told what to fix.
     */
    public function testAFullSyncMovesRecordsAndStillReportsTheActivityFault(): void
    {
        $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG);

        $crm = new FakeCrmAdapter(
            [new Contact('c-1', ['email' => 'ada@example.com', 'firstname' => 'Ada'])],
            [new Account('a-1', ['name' => 'Acme'])],
        );
        $cc = new FakeCcAdapter();
        $logger = new CapturingLogger();

        $engine = new SyncEngine($cc, $crm, $config, $logger);
        $result = $engine->fullSync();

        self::assertNotNull($result->contact);
        self::assertSame(1, $result->contact->getTotalCount(), 'contacts still sync');
        self::assertNotNull($result->account);
        self::assertSame(1, $result->account->getTotalCount(), 'accounts still sync');

        self::assertTrue($result->hasStepFailures(), 'the run must not look clean');
        self::assertArrayHasKey('activity', $result->stepFailures);
        self::assertStringContainsString('names a cc_field', $result->stepFailures['activity']);

        self::assertNotEmpty(
            array_filter($logger->messagesAt('error'), static fn (string $m) => str_contains($m, 'activity')),
            'the operator has to be told, not just the caller',
        );
    }

    /**
     * A 1.1.0 CRM adapter implements CrmAdapterInterface and nothing else. The
     * capability interfaces added in 1.2.0 must stay strictly opt-in — the engine
     * feature-detects them with instanceof, so an adapter that has never heard of
     * them keeps working on the offset path.
     *
     * This is the promise that was broken in 1.1.0, when three methods were added
     * straight onto CrmAdapterInterface and every out-of-tree adapter stopped
     * loading.
     */
    public function testAnAdapterImplementingOnlyTheBaseInterfaceStillWorks(): void
    {
        $crm = new FakeCrmAdapter([new Contact('c-1', ['email' => 'ada@example.com'])]);

        self::assertNotInstanceOf(SupportsCursorPaginationInterface::class, $crm);
        self::assertNotInstanceOf(SupportsDealLinkingInterface::class, $crm);

        $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG);
        $result = (new SyncEngine(new FakeCcAdapter(), $crm, $config, new NullLogger()))->fullSync();

        self::assertNotNull($result->contact);
        self::assertSame(1, $result->contact->getTotalCount());
    }

    /**
     * An established watermark must survive the upgrade untouched.
     *
     * The seeding rail added in 1.2.0 writes "now" over the watermark on a first
     * run. Reached by an existing deployment it would move the window past
     * everything not yet exported — silently, and with no way back but a forced
     * full sync. It is gated on `$since === null` AND an explicit `initial_sync:
     * now`, so a legacy config clears it twice over; this pins both gates.
     */
    public function testAnEstablishedWatermarkIsNotReseeded(): void
    {
        $statePath = sys_get_temp_dir() . '/legacy-state-' . bin2hex(random_bytes(6)) . '.json';
        $store = new FileSyncStateStore($statePath);
        $watermark = new \DateTimeImmutable('2026-01-01 00:00:00');
        $store->setLastSyncTime('activity', $watermark);

        try {
            $config = (new YamlConfigLoader())->load(self::LEGACY_CONFIG);
            $engine = new SyncEngine(new FakeCcAdapter(), new FakeCrmAdapter(), $config, new NullLogger(), stateStore: $store);
            $engine->fullSync();

            self::assertEquals(
                $watermark,
                $store->getLastSyncTime('activity'),
                'the upgrade must not move an existing watermark',
            );
        } finally {
            @unlink($statePath);
        }
    }
}
