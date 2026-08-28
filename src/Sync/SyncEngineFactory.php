<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Logging\StderrLogger;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\State\FileSyncStateStore;
use Psr\Log\LoggerInterface;

final class SyncEngineFactory
{
    private readonly SyncEngine $engine;

    private readonly ContactCentreAdapterInterface $ccAdapter;

    private readonly CrmAdapterInterface $crmAdapter;

    private function __construct(
        SyncEngine $engine,
        ContactCentreAdapterInterface $ccAdapter,
        CrmAdapterInterface $crmAdapter,
    ) {
        $this->engine = $engine;
        $this->ccAdapter = $ccAdapter;
        $this->crmAdapter = $crmAdapter;
    }

    public static function fromYaml(
        string $configPath,
        CrmAdapterInterface $crmAdapter,
        ?LoggerInterface $logger = null,
        ?string $stateStorePath = null,
    ): self {
        $logger ??= new StderrLogger();

        $syncConfig = (new YamlConfigLoader())->load($configPath);
        $ccAdapter = new DaktelaAdapter($syncConfig->instanceUrl, $syncConfig->accessToken, $syncConfig->database, $logger);

        $registry = TransformerRegistry::withDefaults();

        // No invented default. Deriving one from $configPath put mutable runtime
        // state in the config directory: often a read-only mount, usually
        // deploy-scoped (so a redeploy silently reseeds the watermark and skips
        // everything that closed in the gap), and CWD-relative for the relative
        // paths every example passes. Instead, say what is missing — the engine
        // would refuse this config anyway, and here the message can name the
        // parameter to set.
        //
        // Gated on an EXPLICIT initial_sync: now. A config that omits the key
        // predates it (new in 1.2.0) and keeps the pre-1.2.0 behaviour, so this
        // two-argument fromYaml() call — the one every 1.x example used — keeps
        // building. SyncEngine warns about it instead.
        $activityConfig = $syncConfig->getEntityConfig('activity');
        if ($stateStorePath === null
            && $activityConfig !== null
            && $activityConfig->enabled
            && $activityConfig->initialSync === 'now'
        ) {
            throw ConfigurationException::factoryNeedsAStateStorePath();
        }

        $stateStore = $stateStorePath !== null ? new FileSyncStateStore($stateStorePath) : null;

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $syncConfig, $logger, $registry, $stateStore);

        return new self($engine, $ccAdapter, $crmAdapter);
    }

    public function getEngine(): SyncEngine
    {
        return $this->engine;
    }

    public function getCcAdapter(): ContactCentreAdapterInterface
    {
        return $this->ccAdapter;
    }

    public function getCrmAdapter(): CrmAdapterInterface
    {
        return $this->crmAdapter;
    }
}
