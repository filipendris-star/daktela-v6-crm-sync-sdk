# Sync Engine

The `SyncEngine` orchestrates syncing between adapters using field mappings.

## Quick Start with SyncEngineFactory

`SyncEngineFactory` wires the Daktela adapter, config, transformers, and state store from a single YAML config — you provide your `CrmAdapterInterface`:

```php
use Daktela\CrmSync\Sync\SyncEngineFactory;

$crmAdapter = new YourCrmAdapter(/* ... */);
$factory = SyncEngineFactory::fromYaml('config/sync.yaml', $crmAdapter, stateStorePath: 'var/sync-state.json');
$engine = $factory->getEngine();

$engine->testConnections();

$results = $engine->fullSync();
foreach ($results->toArray() as $type => $result) {
    echo $result->getSummary(ucfirst($type)) . "\n";
}
```

The factory creates the Daktela adapter, loads config, registers transformers, and builds the engine. Pass an optional `LoggerInterface` as the third argument (defaults to `StderrLogger`).

## Connection Test

Verify both API connections before syncing:

```php
$engine->testConnections(); // throws RuntimeException on failure
```

## Full Sync (Recommended)

The `fullSync()` method handles all entity types in the correct dependency order:

1. **Accounts** (CRM → Daktela) — synced first, relation map populated automatically
2. **Contacts** (CRM → Daktela) — account references resolved via relation map; missing accounts auto-fetched on-the-fly
3. **Activities** (Daktela → CRM)

```php
$results = $engine->fullSync();

foreach ($results->toArray() as $type => $result) {
    echo $result->getSummary(ucfirst($type)) . "\n";
}
```

Only enabled entities (per config) are synced. Disabled entities are skipped.

## Individual Batch Sync

Process a single entity type:

```php
// Contacts: CRM → Daktela
$result = $engine->syncContactsBatch();

// Accounts: CRM → Daktela
$result = $engine->syncAccountsBatch();

// Activities: Daktela → CRM
$result = $engine->syncActivitiesBatch();
$result = $engine->syncActivitiesBatch([ActivityType::Call, ActivityType::Email]);
```

**Note:** If a contact references an account that hasn't been synced yet, `BatchSync` automatically fetches it from the CRM and syncs it on-the-fly. Syncing accounts before contacts is still recommended for efficiency (avoids per-contact lookups), but is no longer required.

All batch methods (`fullSync()`, `syncContactsBatch()`, `syncAccountsBatch()`, `syncActivitiesBatch()`) automatically loop through all records in batches of `batch_size`. Each method accepts an optional `onBatch` callback for per-batch progress reporting:

```php
$result = $engine->syncContactsBatch(function (string $entityType, SyncResult $batch) use ($logger) {
    $logger->info($batch->getSummary(ucfirst($entityType)));
});
```

## Single-Record Sync

For webhook-triggered sync of individual records:

```php
$result = $engine->syncContact('crm-contact-id');
$result = $engine->syncAccount('crm-account-id');
$result = $engine->syncActivity('call-123', ActivityType::Call);
```

## SyncResult

Every sync operation returns a `SyncResult`:

```php
$result->getTotalCount();    // Total records processed
$result->getCreatedCount();  // New records created in target
$result->getUpdatedCount();  // Existing records updated
$result->getSkippedCount();  // Records skipped (e.g., not found)
$result->getFailedCount();   // Records that failed
$result->getDuration();      // Time in seconds
$result->getRecords();       // All RecordResult objects
$result->getFailedRecords(); // Only failed RecordResult objects
$result->getSummary('Label'); // "Label: 5 total, 2 created, 1 updated, 1 skipped, 1 failed (0.12s)"
$result->isExhausted();      // True if all source records were processed (no more batches)
```

Each `RecordResult` contains:

```php
$record->entityType;    // 'contact', 'account', 'activity'
$record->sourceId;      // ID in source system
$record->targetId;      // ID in target system (null if failed)
$record->status;        // SyncStatus enum: Created, Updated, Skipped, Failed
$record->errorMessage;  // Error details if failed
```

## Contact → Account Relationship

Daktela contacts reference accounts by the account's `name` field (unique identifier). When syncing contacts from a CRM, the CRM typically uses its own internal account IDs.

The SDK resolves this automatically when you:

1. Configure a `relation` in your contact mapping YAML:

```yaml
# contacts.yaml
- cc_field: account        # Daktela field
  crm_field: company_id    # CRM field
  relation:
    entity: account        # Related entity
    resolve_from: id       # CRM account field to match
    resolve_to: name       # Daktela account field to use
```

2. Use `fullSync()` (recommended) or sync accounts before contacts. If a contact references an account not yet in the relation map, the engine automatically fetches it from the CRM and syncs it on-the-fly.

The engine builds a map like:
```
CRM account.id    → CRM account.external_id (mapped to Daktela name)
"crm-acc-123"     → "acme"
"crm-acc-456"     → "globex"
```

Then when a CRM contact has `company_id = "crm-acc-123"`, it gets resolved to `account = "acme"` in Daktela.

## Error Handling

The sync engine catches per-record errors and continues processing. Failed records are captured in `SyncResult::getFailedRecords()` rather than throwing exceptions.

```php
$result = $engine->syncContactsBatch();

if ($result->getFailedCount() > 0) {
    foreach ($result->getFailedRecords() as $failed) {
        $logger->error('Sync failed for {type} {id}: {msg}', [
            'type' => $failed->entityType,
            'id' => $failed->sourceId,
            'msg' => $failed->errorMessage,
        ]);
    }
}
```

## Change Detection

When upserting contacts and accounts to Daktela, the adapter compares mapped field values against the existing record. If no fields have changed, the PUT API call is skipped entirely and the record is counted as "skipped" in `SyncResult`. This saves one API call per unchanged record during incremental syncs.

- **Record with changes:** 1 find + 1 PUT = 2 API calls (same as before)
- **Record with no changes:** 1 find = 1 API call (saves 1 PUT)

Skipped records are still tracked in relation maps and appear in `SyncResult::getSkippedCount()`.

## Custom Transformer Registry

Pass a custom `TransformerRegistry` to the engine:

```php
$registry = TransformerRegistry::withDefaults();
$registry->register(new MyCustomTransformer());

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, $registry);
```

## Incremental Sync

By default, every batch sync fetches all records. To enable incremental sync, pass a `SyncStateStoreInterface` implementation to the engine. The SDK ships with `FileSyncStateStore`:

```php
use Daktela\CrmSync\State\FileSyncStateStore;

$stateStore = new FileSyncStateStore('/var/data/myapp/sync-state.json');

$engine = new SyncEngine(
    ccAdapter: $ccAdapter,
    crmAdapter: $crmAdapter,
    config: $config,
    logger: $logger,
    stateStore: $stateStore,
);
```

**Behavior:**

- **First run** (no saved state) — full sync, same as without a state store
- **Subsequent runs** — the saved timestamp is passed as `$since` to adapter `iterate*()` methods, so only records modified since the last successful sync are returned

**When state is saved:**

State is saved after all batches for an entity type have been processed, unless *every* record failed — that is the only case in which the timestamp is withheld and the next run re-covers the same window. A **partial** failure still advances the timestamp, so individually failed records fall outside the next incremental window until their source timestamp changes again or a forced full sync runs; watch `SyncResult` for them if you need to re-drive them. See the [Production Deployment](09-production-deployment.md) guide for details on safety guarantees.

## Auto-Create Contact from Account

In Daktela, activities can only relate to contacts (not accounts). If an account has contact info (phone, email) and someone calls from that number, the activity won't be paired because there's no contact entity with that info.

The `auto_create_contact` feature solves this by automatically creating a "default contact" from an account's contact info fields after each account sync. This contact links to the parent account and has the same phone/email, so Daktela can pair inbound activities to it.

### Configuration

Add `auto_create_contact` to the account entity in `sync.yaml`:

```yaml
sync:
  entities:
    account:
      enabled: true
      direction: crm_to_cc
      mapping_file: "mappings/accounts.yaml"
      auto_create_contact:
        mapping_file: "mappings/account-contact.yaml"
        skip_if_empty:
          - email
          - number
        skip_if_exists:
          - email
          - number
        skip_if_exists_mode: all  # or "any"
```

The referenced mapping file uses the same format as regular mappings. `crm_field` references CRM account entity fields, `cc_field` references Daktela contact fields:

```yaml
entity: contact
lookup_field: name

mappings:
  - cc_field: title
    crm_field: company_name
  - cc_field: name
    crm_field: external_id
    transformers:
      - name: prefix
        params: { value: "company_" }
  - cc_field: email
    crm_field: email
  - cc_field: number
    crm_field: phone
```

### Behavior

- The `account` field on the auto-created contact is always set to the parent account's Daktela ID — you don't need to map it.
- The contact is upserted using the mapping's `lookup_field`, so subsequent syncs update it rather than creating duplicates.
- Works in both batch sync and webhook sync.

### Skip when empty with `skip_if_empty`

The optional `skip_if_empty` lists CC field names that are checked after mapping. If **all** listed fields are empty (null, empty string, or empty array), the auto-contact is not created. This prevents creating useless contacts from accounts that have no contact info.

The check runs before any API calls, so no network overhead is incurred when skipping.

### Dedup with `skip_if_exists`

The optional `skip_if_exists` lists CC field names to check before creating a new auto-contact. The `skip_if_exists_mode` controls how the fields are matched:

- **`all`** (default) — skip only when a single existing contact under the same account matches **all** listed fields. This uses one API call with all criteria combined.
- **`any`** — skip when **any** listed field matches an existing contact. Each field is checked independently (separate API calls), so matches can be on different contacts.

This prevents duplicates when a real person contact has already been synced with the same email or phone. The check only runs when the auto-contact doesn't exist yet — if the auto-contact already exists, it's updated normally without re-checking.

## Force Full Sync

Ignore saved state and sync all records:

```php
$results = $engine->fullSync(forceFullSync: true);
```

Use cases:
- Initial data load into a fresh Daktela instance
- Recovery after data corruption or manual changes
- After modifying field mapping configuration

The force flag is temporary — it only applies to that single `fullSync()` call. Subsequent calls resume incremental behavior.

The flag resets both incremental inputs: the per-entity `lastSyncTime` **and**
any persisted pagination cursor (a cursor-paginated adapter would otherwise
resume mid-drain from a stale token instead of starting over).

## Activity Export Idempotency Ledger

Most CRMs cannot search activities server-side, so the usual find-then-upsert
dedup does not work for `cc_to_crm` activity export. The engine accepts a
host-supplied ledger that answers "was this CC activity already exported?"
— typically backed by a database table on the host side:

```php
use Daktela\CrmSync\State\SyncLedgerInterface;

final class DbLedger implements SyncLedgerInterface
{
    public function hasSynced(string $entityType, string $ccId): bool { /* SELECT ... */ }
    public function recordSynced(string $entityType, string $ccId, ?string $crmId): void { /* INSERT ... */ }
}

$engine->setLedger(new DbLedger());
```

Behavior when a ledger is set:

- an activity the ledger already knows is reported as `Skipped` — no CRM call;
- new activities are **created directly** (no CRM-side lookup — the ledger owns dedup);
- the pair is recorded after a successful create, with the resulting CRM id;
- failed exports are *not* recorded — they are retried if the sync window has
  not advanced (e.g. a fully failed run holds the watermark);
- a ledger **read** failure aborts the run (the watermark is kept, so the next
  run retries cleanly); a ledger **write** failure after a successful create is
  reported as a Failed record — the CRM record exists but dedup protection for
  it is compromised.

Without a ledger, activity export falls back to the adapter's
`upsertActivity()` lookup-then-write path. Re-runs and forced full re-syncs
never duplicate activities as long as the ledger persists.

The webhook path participates too: `setLedger()` also wires the engine's
`WebhookSync`, which records what it exports — otherwise a webhook-pushed
activity would be created a second time by a later batch run.

One activity emits several webhook events (`call_create` → `call_answer` →
`call_close`), and every event after the first has to **update** the CRM record
the first one created. The adapter's `upsertActivity()` cannot find that record
on the CRMs a ledger exists for (no server-side activity search), so it would
create a new one per event. Implement the optional
`SyncLedgerLookupInterface` (adds `findCrmId()`) and the webhook path updates
the recorded record directly:

```php
final class DbLedger implements SyncLedgerLookupInterface
{
    public function hasSynced(string $entityType, string $ccId): bool { /* … */ }
    public function recordSynced(string $entityType, string $ccId, ?string $crmId): void { /* … */ }
    public function findCrmId(string $entityType, string $ccId): ?string { /* SELECT crm_id … */ }
}
```

Without it the webhook path falls back to the adapter's `upsertActivity()`, as it
always has: on a CRM that *can* find the activity the follow-up events update it
correctly, and on one that cannot they add another record — which is exactly the
duplication `findCrmId()` removes. The SDK does not skip events to avoid that: a
skip would freeze the record even on the CRMs where upsert works.

## Reset State

Clear saved timestamps so the next run starts from scratch:

```php
// Reset all entity types — next fullSync() will be a full sync
$engine->resetState();

// Reset a single entity type — only that entity re-syncs fully
$engine->resetState('contact');
```

If no state store is configured, `resetState()` is a no-op.
