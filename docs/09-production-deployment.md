# Production Deployment

This guide covers how to deploy the SDK in production with cron-based batch sync, real-time webhooks, and incremental sync state tracking.

## Architecture Overview

A production deployment consists of three runtime components:

```
┌─────────────────────────────────────────────────────────────────────┐
│                        Your Application                             │
│                                                                     │
│  ┌──────────────────┐  ┌─────────────────┐  ┌───────────────────┐  │
│  │  Cron / CLI      │  │  Daktela        │  │  CRM Webhook      │  │
│  │  bin/sync.php    │  │  Webhook        │  │  Endpoint         │  │
│  │                  │  │  Endpoint       │  │                   │  │
│  │  fullSync()      │  │  WebhookHandler │  │  syncContact()    │  │
│  │  (scheduled)     │  │  (real-time)    │  │  syncAccount()    │  │
│  └────────┬─────────┘  └───────┬─────────┘  └────────┬──────────┘  │
│           │                    │                      │             │
│           └────────────┬───────┴──────────────────────┘             │
│                        ▼                                            │
│                  ┌──────────┐                                       │
│                  │SyncEngine│                                       │
│                  └─────┬────┘                                       │
│                        │                                            │
│              ┌─────────┴─────────┐                                  │
│              ▼                   ▼                                   │
│        ┌──────────┐       ┌───────────┐                             │
│        │ CRM      │       │ Daktela   │                             │
│        │ Adapter  │       │ Adapter   │                             │
│        └──────────┘       └───────────┘                             │
└─────────────────────────────────────────────────────────────────────┘
```

| Component | Trigger | What it does |
|-----------|---------|-------------|
| **Cron job** | Schedule (e.g. every 5 min) | Runs `fullSync()` — batch syncs all entity types |
| **Daktela webhook endpoint** | Daktela automation HTTP request | Receives activity events, calls `syncActivity()` via `WebhookHandler` |
| **CRM webhook endpoint** | CRM webhook HTTP request | Receives contact/account updates, calls `syncContact()`/`syncAccount()` |

## Incremental Sync Setup

Without a state store, every `fullSync()` call re-syncs all records from scratch. For the activity EXPORT with `initial_sync: now` (the default) this is refused outright, because re-pushing the full contact-centre history on every run is never intended. The `FileSyncStateStore` enables incremental sync by saving the last successful sync timestamp per entity type.

### Wiring (using SyncEngineFactory)

```php
use Daktela\CrmSync\Sync\SyncEngineFactory;

$crmAdapter = new YourCrmAdapter(/* ... */);
$factory = SyncEngineFactory::fromYaml('config/sync.yaml', $crmAdapter, stateStorePath: 'var/sync-state.json');
$engine = $factory->getEngine();
```

### Wiring (manual)

```php
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncEngine;

$stateStore = new FileSyncStateStore('/var/data/myapp/sync-state.json');

$engine = new SyncEngine(
    ccAdapter: $ccAdapter,
    crmAdapter: $crmAdapter,
    config: $config,
    logger: $logger,
    stateStore: $stateStore,
);
```

### How It Works

1. On each `fullSync()`, the engine checks the state store for the last sync timestamp per entity type
2. If a timestamp exists, it passes it as `$since` to the adapter's `iterateContacts($since)`, `iterateAccounts($since)`, etc.
3. Your CRM adapter should use this timestamp to return only records modified since that time
4. After a successful sync, the timestamp is saved

### Safety Guarantees

The engine loops through all batches for an entity type before saving that
entity's timestamp, and withholds it entirely when **every** record failed — so
a total outage re-covers the same window on the next run.

**A partial failure still advances the timestamp.** Individually failed records
are reported in `SyncResult`, but they are *not* re-offered on the next run:
their source timestamp has not moved, so they fall outside the incremental
window. If you need them retried, read the failures off `SyncResult` and
re-drive them, or run a forced full sync. Do not assume a failed record comes
back on its own.

**Give the scheduled run a timeout.** The SDK trusts an adapter's pagination: a
fresh page token means "there is more", however many record-less pages precede
it, because a filtered search legitimately scans many. An adapter whose
`has_more` never turns false therefore drains until it is stopped, so bounding a
run's total work belongs to whatever schedules it (`timeout`, a systemd
`RuntimeMaxSec`, or equivalent).

### State File

The state file is a simple JSON file:

```json
{
    "account": "2025-01-15T10:30:00+00:00",
    "contact": "2025-01-15T10:30:05+00:00",
    "activity": "2025-01-15T10:30:12+00:00"
}
```

Choose a writable directory outside the web root (e.g. `/var/data/myapp/`). The directory is created automatically if it doesn't exist.

## Cron / Scheduler Setup

### CLI Sync Script

Create a `bin/sync.php` script:

```php
#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Daktela\CrmSync\Sync\SyncEngineFactory;

$configPath = getenv('SYNC_CONFIG_PATH') ?: __DIR__ . '/../config/sync.yaml';

$crmAdapter = new YourCrmAdapter(/* ... */);
$factory = SyncEngineFactory::fromYaml($configPath, $crmAdapter, stateStorePath: __DIR__ . '/../var/sync-state.json');
$engine = $factory->getEngine();

// Parse CLI arguments
$forceFullSync = in_array('--force-full', $argv, true);
$resetState = in_array('--reset-state', $argv, true);
$resetEntity = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--reset-entity=')) {
        $resetEntity = substr($arg, strlen('--reset-entity='));
    }
}

if ($resetState) {
    $engine->resetState();
    fprintf(STDERR, "All sync state cleared\n");
    exit(0);
}

if ($resetEntity !== null) {
    $engine->resetState($resetEntity);
    fprintf(STDERR, "Sync state cleared for: %s\n", $resetEntity);
    exit(0);
}

$engine->testConnections();

$results = $engine->fullSync(forceFullSync: $forceFullSync);

$hasFailures = false;
foreach ($results->toArray() as $type => $result) {
    fprintf(STDERR, "%s\n", $result->getSummary(ucfirst($type)));

    if ($result->getFailedCount() > 0) {
        $hasFailures = true;
        foreach ($result->getFailedRecords() as $failed) {
            fprintf(STDERR, "  Failed %s %s: %s\n", $failed->entityType, $failed->sourceId, $failed->errorMessage);
        }
    }
}

exit($hasFailures ? 1 : 0);
```

### Usage

```bash
# Incremental sync (default — uses saved state)
php bin/sync.php

# Force full re-sync (ignores saved state)
php bin/sync.php --force-full

# Reset all sync state (next run will be a full sync)
php bin/sync.php --reset-state

# Reset state for a single entity type
php bin/sync.php --reset-entity=contact
```

### Crontab

```cron
# Incremental sync every 5 minutes
*/5 * * * * cd /path/to/project && php bin/sync.php >> /dev/null 2>&1
```

Adjust the frequency based on your data volume and freshness requirements.

## Webhook Endpoints

### Daktela → Your App (Activities)

The SDK includes a ready-made `WebhookHandler` for receiving Daktela automation events. See the [Webhooks](06-webhooks.md) guide for full setup instructions, including:

- Creating the HTTP endpoint (plain PHP and Laravel examples)
- Configuring Daktela automations to send HTTP requests
- Payload format and event-type mapping
- Secret validation

### CRM → Your App (Contacts & Accounts)

When contacts or accounts are updated in your CRM, you can set up a webhook endpoint to sync those changes to Daktela in real-time.

Your CRM adapter's `findContact()` / `findAccount()` methods are used under the hood — the SDK fetches the latest data from the CRM and pushes it to Daktela.

#### Plain PHP

```php
// public/webhook/crm.php
require __DIR__ . '/../../vendor/autoload.php';

// ... bootstrap $engine (same as cron setup, but stateStore is not needed for webhooks)

$payload = json_decode(file_get_contents('php://input'), true);

if ($payload === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Extract entity type and ID from your CRM's webhook payload.
// The exact structure depends on your CRM — adapt as needed.
$entityType = $payload['entity_type'] ?? null; // e.g. 'contact', 'account'
$entityId = $payload['entity_id'] ?? null;

if ($entityType === null || $entityId === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing entity_type or entity_id']);
    exit;
}

try {
    $result = match ($entityType) {
        'contact' => $engine->syncContact($entityId),
        'account' => $engine->syncAccount($entityId),
        default => null,
    };

    if ($result === null) {
        http_response_code(400);
        echo json_encode(['error' => "Unknown entity type: {$entityType}"]);
        exit;
    }

    $statusCode = $result->getFailedCount() > 0 ? 207 : 200;

    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $result->getFailedCount() > 0 ? 'partial' : 'ok',
        'total' => $result->getTotalCount(),
        'created' => $result->getCreatedCount(),
        'updated' => $result->getUpdatedCount(),
        'failed' => $result->getFailedCount(),
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
```

#### Laravel

```php
// routes/api.php
Route::post('/webhook/crm', [CrmWebhookController::class, 'handle']);

// app/Http/Controllers/CrmWebhookController.php
class CrmWebhookController extends Controller
{
    public function handle(Request $request, SyncEngine $engine): JsonResponse
    {
        $request->validate([
            'entity_type' => 'required|in:contact,account',
            'entity_id' => 'required|string',
        ]);

        $result = match ($request->input('entity_type')) {
            'contact' => $engine->syncContact($request->input('entity_id')),
            'account' => $engine->syncAccount($request->input('entity_id')),
        };

        return response()->json([
            'status' => $result->getFailedCount() > 0 ? 'partial' : 'ok',
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'failed' => $result->getFailedCount(),
        ], $result->getFailedCount() > 0 ? 207 : 200);
    }
}
```

## Recommended Setup

For a complete production deployment:

1. **Initial data load** — run `fullSync(forceFullSync: true)` once to populate Daktela with all existing CRM data:
   ```bash
   php bin/sync.php --force-full
   ```

2. **Scheduled incremental sync** — set up a cron job for `fullSync()` every N minutes. With the state store, only modified records are synced.

3. **Daktela activity webhooks** — configure Daktela automations to push activity events (calls, emails, chats) to your webhook endpoint for real-time sync to the CRM. See [Webhooks](06-webhooks.md).

4. **CRM contact/account webhooks** — configure your CRM to push contact and account changes to your webhook endpoint for real-time sync to Daktela.

The incremental cron job acts as a **safety net** — even if a webhook is missed or fails, the next cron run picks up any changes since the last successful sync.

## Operations

### Force Full Re-Sync

Ignores saved state and syncs all records:

```php
$engine->fullSync(forceFullSync: true);
```

Use cases: initial data load, data corruption recovery, after mapping changes.

### Reset State

Clear saved timestamps so the next sync run starts without an incremental window:

```php
// Reset all entity types
$engine->resetState();

// Reset a single entity type
$engine->resetState('contact');
```

**A reset alone does not re-push history for exports.** Clearing a watermark
makes the next run look like a *first* run, and a first run of a `cc_to_crm`
entity with `initial_sync: now` (the default — activities; custom-entity export
is not part of this release and has no seed rail) seeds the watermark back to
now and pushes nothing. That run reports
`0 total, 0 failed` and looks clean while the history stays permanently outside
the window. `resetState()` logs a warning naming the affected entities, and so
does the run that re-seeds.

Imports (`crm_to_cc`: contacts, accounts) have no seed rail, so a reset does
exactly what it says for them.

To actually re-drive history, use a forced run — it ignores both the watermark
and the seed rail, so no reset is needed at all:

```php
$engine->fullSync(forceFullSync: true);
```

Or set `initial_sync: everything` on the entity, which makes a first run push
full history. `forceFullSync` is the better tool for a one-off recovery: it is a
per-run decision rather than a config change someone has to remember to revert.

### Monitoring

Check `SyncResult` for failures after each run:

```php
foreach ($results->toArray() as $type => $result) {
    echo $result->getSummary(ucfirst($type)) . "\n";
    // Output: Contact: 150 total, 5 created, 10 updated, 130 skipped, 5 failed (2.34s)

    if ($result->getFailedCount() > 0) {
        foreach ($result->getFailedRecords() as $failed) {
            fprintf(STDERR, "  Failed %s %s: %s\n", $failed->entityType, $failed->sourceId, $failed->errorMessage);
        }
    }
}
```

The CLI script exits with code `1` when there are failures, which can be used for alerting in your cron monitoring tool.

### State File Inspection

The state file is human-readable JSON. You can inspect it to see the last successful sync time per entity:

```bash
cat var/data/sync-state.json
```

```json
{
    "account": "2025-01-15T10:30:00+00:00",
    "contact": "2025-01-15T10:30:05+00:00",
    "activity": "2025-01-15T10:30:12+00:00"
}
```
