# Daktela CRM Sync

A universal sync SDK between **Daktela Contact Centre V6** and any CRM system. Provides the sync engine, field mapper, transformers, state tracking, and webhook handling — you supply a `CrmAdapterInterface` implementation for your CRM.

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────────┐
│  CRM System │ ──▶ │ Sync Engine │ ──▶ │ Daktela CC V6   │
│  (Adapter)  │ ◀── │  + Mapper   │ ◀── │   (Adapter)     │
└─────────────┘     └─────────────┘     └─────────────────┘
      │                    │                     │
      │              YAML Configs          Official PHP
      │            (field mappings)        Connector v2.4
```

**Sync directions:**
- **Contacts**: CRM → Daktela (CRM is source-of-truth)
- **Accounts**: CRM → Daktela (CRM is source-of-truth)
- **Activities**: Daktela → CRM (Daktela is source-of-truth)

## Requirements

- PHP 8.2+
- Daktela V6 instance with API access

## Installation

```bash
composer require daktela/daktela-v6-crm-sync
```

## Pre-Built Adapters

The companion package [`daktela/daktela-crm-integrations`](https://github.com/Daktela/daktela-crm-integrations) provides ready-to-use adapters for 15 CRM/ERP systems — including HubSpot, Salesforce, Pipedrive, SugarCRM, Dynamics 365, Raynet, WooCommerce, and more. Install with `composer require daktela/daktela-crm-integrations`.

## Quick Start

1. Install a pre-built adapter or create your own implementing `CrmAdapterInterface`
2. Configure field mappings in YAML
3. Wire up the `SyncEngine`

```php
use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Logging\StderrLogger;
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncEngine;

$logger = new StderrLogger();
$config = (new YamlConfigLoader())->load('config/sync.yaml');

$ccAdapter = new DaktelaAdapter($config->instanceUrl, $config->accessToken, $config->database, $logger);
$crmAdapter = new YourCrmAdapter(/* ... */);

$stateStore = new FileSyncStateStore(__DIR__ . '/var/sync-state.json');
$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, stateStore: $stateStore);
$engine->testConnections();

$results = $engine->fullSync();
foreach ($results->toArray() as $type => $result) {
    echo $result->getSummary(ucfirst($type)) . "\n";
}
```

> **Activity export needs a state store.** With the default `initial_sync: now`
> the engine seeds a watermark on the first run and pushes no history. Without a
> state store there is nothing to seed, so the engine refuses rather than push the
> full contact-centre history on every run. Pass `stateStore:` as shown in
> `examples/bootstrap.php`. To push history on purpose, use
> `fullSync(forceFullSync: true)` or `initial_sync: everything`.

See [`examples/`](examples/) for full sync, incremental, single-record, and webhook examples.

## Documentation

- [Getting Started](docs/01-getting-started.md)
- [Configuration](docs/02-configuration.md)
- [Field Mapping](docs/03-field-mapping.md)
- [Implementing a CRM Adapter](docs/04-implementing-crm-adapter.md)
- [Sync Engine](docs/05-sync-engine.md)
- [Webhooks](docs/06-webhooks.md)
- [Error Handling](docs/07-error-handling.md)
- [Testing Your Integration](docs/08-testing-your-integration.md)
- [Production Deployment](docs/09-production-deployment.md)

## Development

```bash
docker compose build
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse
```

## License

Proprietary — requires a valid Daktela Contact Centre license. See [LICENSE](LICENSE) for details.
