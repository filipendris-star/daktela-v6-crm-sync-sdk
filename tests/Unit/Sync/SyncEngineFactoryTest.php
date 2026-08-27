<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Sync\SyncEngineFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SyncEngineFactoryTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../Fixtures/config/sync_with_activity_export.yaml';

    /**
     * The factory must not invent a state-store path.
     *
     * It briefly defaulted to `dirname($configPath) . '/sync-state.json'`, which
     * put mutable runtime state in the config directory: often a read-only
     * mount, usually deploy-scoped (a redeploy reseeds the watermark and
     * silently skips everything that closed in the gap), and CWD-relative for
     * the relative paths every example passes. Saying what is missing is safer
     * than guessing, and the message can name the parameter.
     */
    public function testAnActivityExportConfigWithoutAStateStorePathIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Pass \$stateStorePath/');

        SyncEngineFactory::fromYaml(
            self::CONFIG,
            $this->createMock(CrmAdapterInterface::class),
            new NullLogger(),
        );
    }

    public function testTheErrorNamesAPathOutsideTheConfigAndReleaseDirectories(): void
    {
        try {
            SyncEngineFactory::fromYaml(
                self::CONFIG,
                $this->createMock(CrmAdapterInterface::class),
                new NullLogger(),
            );
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('$stateStorePath', $e->getMessage(), 'must name the parameter to set');
            self::assertStringContainsString('survives a redeploy', $e->getMessage(), 'and why the location matters');
        }
    }

    /**
     * The refusal is narrow on purpose, and both narrowing conditions were
     * unpinned: dropping either left the suite green while the factory started
     * rejecting configs the engine explicitly allows.
     */
    public function testADisabledActivityEntityNeedsNoStateStorePath(): void
    {
        $factory = SyncEngineFactory::fromYaml(
            __DIR__ . '/../../Fixtures/config/sync_activity_disabled.yaml',
            $this->createMock(CrmAdapterInterface::class),
            new NullLogger(),
        );

        self::assertInstanceOf(SyncEngineFactory::class, $factory);
    }

    /**
     * `initial_sync: everything` asks for the history, so it needs no watermark
     * to seed — SyncEngine allows it with a warning, and the factory must not
     * contradict that.
     */
    public function testEverythingNeedsNoStateStorePath(): void
    {
        $factory = SyncEngineFactory::fromYaml(
            __DIR__ . '/../../Fixtures/config/sync_activity_everything.yaml',
            $this->createMock(CrmAdapterInterface::class),
            new NullLogger(),
        );

        self::assertInstanceOf(SyncEngineFactory::class, $factory);
    }

    public function testAnExplicitStateStorePathBuildsTheEngine(): void
    {
        $path = sys_get_temp_dir() . '/factory-state-' . bin2hex(random_bytes(6)) . '.json';

        $factory = SyncEngineFactory::fromYaml(
            self::CONFIG,
            $this->createMock(CrmAdapterInterface::class),
            new NullLogger(),
            $path,
        );

        // assertInstanceOf() would pass for any implementation that returns, the
        // return type being declared `: self` — it proved "did not throw" and
        // nothing about the store. Prove the path was actually wired: resetState()
        // reaches the store, and the engine's own seed guard would have thrown on
        // this config if $stateStore had come out null.
        $factory->getEngine()->resetState('activity');

        self::assertFileExists($path, 'the state store must be wired to the path that was passed');
        @unlink($path);
    }
}
