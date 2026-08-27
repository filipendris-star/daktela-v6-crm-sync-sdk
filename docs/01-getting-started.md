# Getting Started

## Installation

```bash
composer require daktela/daktela-v6-crm-sync
```

## Prerequisites

- PHP 8.2 or higher
- A Daktela V6 instance with API access token
- A CRM system you want to integrate

## Quick Overview

The SDK syncs three entity types between Daktela and your CRM:

| Entity | Direction | Source of Truth |
|--------|-----------|-----------------|
| Contacts | CRM → Daktela | CRM |
| Accounts | CRM → Daktela | CRM |
| Activities | Daktela → CRM | Daktela |

## Basic Setup

### 1. Create Configuration Files

Create a `config/sync.yaml` file:

```yaml
daktela:
  instance_url: "https://your-instance.daktela.com"
  access_token: "${DAKTELA_ACCESS_TOKEN}"
  database: "default"

sync:
  batch_size: 100
  entities:
    contact:
      enabled: true
      direction: crm_to_cc
      mapping_file: "mappings/contacts.yaml"
    account:
      enabled: true
      direction: crm_to_cc
      mapping_file: "mappings/accounts.yaml"
    activity:
      enabled: true
      direction: cc_to_crm
      mapping_file: "mappings/activities.yaml"
      activity_types: [call, email]

webhook:
  secret: "${WEBHOOK_SECRET}"
```

### 2. Create Field Mappings

Create `config/mappings/contacts.yaml`:

```yaml
entity: contact
lookup_field: email
mappings:
  - cc_field: title
    crm_field: full_name
  - cc_field: email
    crm_field: email
  - cc_field: number
    crm_field: phone
    transformers:
      - name: phone_normalize
        params: { format: e164 }
  - cc_field: account
    crm_field: company_id
    relation:
      entity: account
      resolve_from: id
      resolve_to: name
```

Create `config/mappings/accounts.yaml`:

```yaml
entity: account
lookup_field: name
mappings:
  - cc_field: title
    crm_field: company_name
  - cc_field: name
    crm_field: external_id
```

Create `config/mappings/activities.yaml`:

```yaml
entity: activity
# The CRM-side field the mapping writes the Daktela id into. On export the
# existence check runs against the mapped CRM payload, so this must name a
# crm_field below — naming a cc_field makes every run create a duplicate.
lookup_field: external_id
mappings:
  - cc_field: name
    crm_field: external_id
  - cc_field: title
    crm_field: subject
  - cc_field: time_start
    crm_field: start_time
    transformers:
      - name: date_format
        params: { from: "Y-m-d H:i:s", to: "c" }
```

### 3. Choose or Implement a CRM Adapter

**Option A: Use a pre-built adapter** from [`daktela/daktela-crm-integrations`](https://github.com/Daktela/daktela-crm-integrations):

```bash
composer require daktela/daktela-crm-integrations
```

Available adapters: HubSpot, Salesforce, Pipedrive, SugarCRM, Dynamics 365, Dynamics 365 Premise, Raynet, WooCommerce, Shoptet, MoneyS5, Abra, K2, Dynamics 365 Finance, Billingo, PrestaShop. Each adapter provides a `SyncEngineFactory` for one-line setup — see the [integrations documentation](https://github.com/Daktela/daktela-crm-integrations) for details.

**Option B: Implement a custom adapter** by creating a class that implements `CrmAdapterInterface`.

See [Implementing a CRM Adapter](04-implementing-crm-adapter.md) for a complete guide with examples.

### 4. Wire Everything Together

```php
use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Logging\StderrLogger;
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncEngine;

$logger = new StderrLogger();
$config = (new YamlConfigLoader())->load(__DIR__ . '/config/sync.yaml');

$ccAdapter = new DaktelaAdapter(
    $config->instanceUrl,
    $config->accessToken,
    $config->database,
    $logger,
);

$crmAdapter = new YourCrmAdapter(/* your CRM connection params */);

$stateStore = new FileSyncStateStore(__DIR__ . '/var/sync-state.json');
$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, stateStore: $stateStore);
```

> **Activity export needs a state store.** With the default `initial_sync: now`
> the engine seeds a watermark on the first run and pushes no history. Without a
> state store there is nothing to seed, so the engine refuses rather than push the
> full contact-centre history on every run. Pass `stateStore:` as shown in
> `examples/bootstrap.php`. To push history on purpose, use
> `fullSync(forceFullSync: true)` or `initial_sync: everything`.

### 5. Run a Sync

```php
// Verify connectivity
$engine->testConnections();

// Full sync (recommended — handles dependencies automatically)
$results = $engine->fullSync();

foreach ($results->toArray() as $type => $result) {
    echo $result->getSummary(ucfirst($type)) . "\n";
}
// Output: Account: 42 total, 5 created, 10 updated, 25 skipped, 2 failed (1.23s)
```

**Individual entity sync:**

```php
$engine->syncAccountsBatch();               // Sync accounts first
$result = $engine->syncContactsBatch();     // Then contacts
$result = $engine->syncActivitiesBatch();   // Then activities
echo $result->getSummary('Activities');
```

> **Tip:** The [`examples/`](../examples/) directory contains ready-to-run scripts for all sync
> scenarios — full sync, single entity, single record, incremental, and webhooks.

### 6. Set Up Webhooks (Optional)

For real-time sync triggered by Daktela events, see [Webhooks](06-webhooks.md).

## Next Steps

- [Examples](../examples/) — Ready-to-run scripts for all sync scenarios
- [Configuration Reference](02-configuration.md) — All YAML config options
- [Field Mapping](03-field-mapping.md) — Transformers, multi-value fields, relations
- [Implementing a CRM Adapter](04-implementing-crm-adapter.md) — Complete adapter guide
- [Sync Engine](05-sync-engine.md) — Batch sync, webhook sync, fullSync
- [Webhooks](06-webhooks.md) — Real-time sync with Daktela events
- [Error Handling](07-error-handling.md) — Exceptions, logging, debugging
- [Testing Your Integration](08-testing-your-integration.md) — Test strategies
- [Production Deployment](09-production-deployment.md) — Cron, logging, monitoring
