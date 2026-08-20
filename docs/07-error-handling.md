# Error Handling

## Exception Hierarchy

```
\RuntimeException
  └── SyncException                # Base exception
        ├── AdapterException       # Adapter read/write failures
        │     └── NotSupportedException  # Operation not supported by adapter
        ├── MappingException       # Transformer/mapping issues
        ├── ConfigurationException # Config file issues
        └── StateStoreException    # Sync state persistence errors
```

All SDK exceptions extend `SyncException`, which extends `\RuntimeException`. You can catch `SyncException` to handle all SDK-specific errors.

## AdapterException

Thrown when adapter operations fail (API errors, network issues, etc.):

```php
use Daktela\CrmSync\Exception\AdapterException;

// Static factory methods:
AdapterException::readFailed('contact', 'c-123');     // "Failed to read contact: c-123"
AdapterException::createFailed('contact', $previous); // "Failed to create contact" with cause
AdapterException::updateFailed('contact', 'c-123');   // "Failed to update contact: c-123"
AdapterException::missingId('contact');                // "Missing ID for contact"
```

Use these in your CRM adapter implementation:

```php
public function createActivity(Activity $activity): Activity
{
    try {
        $id = $this->client->create('Task', $activity->toArray());
        return Activity::fromArray(array_merge($activity->toArray(), ['id' => $id]));
    } catch (\Throwable $e) {
        throw AdapterException::createFailed('activity', $e);
    }
}
```

## NotSupportedException

Thrown by adapters that do not support certain operations — typically activity CRUD for read-only adapters (e-commerce platforms, ERPs without activity APIs):

```php
use Daktela\CrmSync\Exception\NotSupportedException;

// Static factory methods:
NotSupportedException::activityNotSupported('WooCommerce');
// → "WooCommerce adapter does not support activity operations"

NotSupportedException::operationNotSupported('Billingo', 'account search');
// → "Billingo adapter does not support account search"
```

`NotSupportedException` extends `AdapterException`, so existing `AdapterException` catch blocks will handle it. Use it when implementing adapters for systems that lack certain API capabilities:

```php
public function createActivity(Activity $activity): Activity
{
    throw NotSupportedException::activityNotSupported('WooCommerce');
}
```

The SDK's `ReadOnlyActivityTrait` (in `daktela-crm-integrations`) provides default implementations of all activity methods that throw this exception automatically.

## MappingException

Thrown for mapping configuration issues at runtime:

```php
use Daktela\CrmSync\Exception\MappingException;

MappingException::unknownTransformer('nonexistent_transform');
```

This is thrown when a YAML mapping references a transformer name that isn't registered in the `TransformerRegistry`.

## ConfigurationException

Thrown during config loading when files are missing or invalid:

```php
use Daktela\CrmSync\Exception\ConfigurationException;

ConfigurationException::fileNotFound('/path/to/config.yaml');
ConfigurationException::invalidMappingFile('/path/to/mapping.yaml', 'Missing entity key');
```

Common causes:
- YAML file path doesn't exist
- Missing required keys (`entity`, `lookup_field`, `mappings`)
- Invalid `direction` value (must be `crm_to_cc`, `cc_to_crm`, or `bidirectional`)
- Invalid `multi_value.strategy` (must be `as_array`, `join`, `split`, `first`, or `last`)
- Incomplete `relation` config (requires `entity`, `resolve_from`, and `resolve_to`)

## Per-Record Error Handling

The sync engine does **not** throw on individual record failures. Instead, failures are captured in `SyncResult` and processing continues with the next record:

```php
$result = $engine->syncContactsBatch();

// Check for failures
if ($result->getFailedCount() > 0) {
    foreach ($result->getFailedRecords() as $failed) {
        $logger->error('Failed to sync {type} {id}: {error}', [
            'type' => $failed->entityType,
            'id' => $failed->sourceId,
            'error' => $failed->errorMessage,
        ]);
    }
}
```

This design ensures that one bad record doesn't stop the entire batch. Each `RecordResult` contains:

```php
$record->entityType;    // 'contact', 'account', 'activity'
$record->sourceId;      // ID in the source system
$record->targetId;      // ID in the target system (null if failed)
$record->status;        // SyncStatus: Created, Updated, Skipped, Failed
$record->errorMessage;  // Error details (null if successful)
```

## fullSync() Error Handling

When using `fullSync()`, each entity type has its own `SyncResult`. A failure in
one entity does not prevent any other from running: steps are not gated on each
other's outcome. A later step that needs a relation the account step would have
mapped resolves it per record instead, and fails only the records it genuinely
cannot resolve — see [Field Mapping](03-field-mapping.md#how-it-works). Gating
whole steps stalled every dependent entity for as long as one account was broken,
while still missing the case that motivated it (a *partial* account failure leaves
the step "ok" and the relation map incomplete).

Two failure levels exist and they need different handling:

- **per-record** failures — `$result->getFailedCount()`, individual records the
  other records' success is unaffected by. The entity's sync window still
  advances (unless *every* record failed), so a failed record is **not** re-offered
  on the next run: its source timestamp has not moved. Read the failures off
  `SyncResult` and re-drive them if that matters to you.
- One per-record failure worth recognising is an **unresolved relation**:

  ```
  Cannot resolve account reference "crm-a9": syncing it failed (…).
  Refusing to write the unresolved CRM id.
  ```

  The referenced entity exists in the CRM but could not be synced (CRM
  unreachable, Daktela write rejected), so the record is failed rather than
  written with a raw CRM foreign key in a relation field — see
  [Field Mapping](03-field-mapping.md#how-it-works). The record is absent from
  Daktela until the referenced entity syncs and the source record is touched
  again, or a forced full sync runs. Recovering the account and re-running with
  `forceFullSync` is the fix.
- **step-level** failures — `$results->stepFailures` (entity type => message),
  a whole step that failed: an adapter fault, a misconfiguration, or every one of
  its records failing. The step's sync window is deliberately **not** advanced, so
  nothing edited during the outage falls out of the incremental window.

`fullSync()` returns normally even when steps failed, so a scheduler must check
`hasStepFailures()` — otherwise a total outage looks like a successful run:

```php
$results = $engine->fullSync();

if ($results->hasStepFailures()) {
    foreach ($results->stepFailures as $entityType => $error) {
        $logger->error('{type} sync step failed: {error}', ['type' => $entityType, 'error' => $error]);
    }

    exit(1); // the run did not sync everything it was asked to
}
```

Per-record failures are reported per entity:

```php
$results = $engine->fullSync();

foreach ($results as $entityType => $result) {
    if ($result->getFailedCount() > 0) {
        $logger->warning('{type} sync had {count} failures', [
            'type' => $entityType,
            'count' => $result->getFailedCount(),
        ]);
    }
}
```

## Webhook Error Handling

The webhook handler returns appropriate HTTP status codes:

| Code | Meaning |
|------|---------|
| `200` | All records synced successfully |
| `207` | Partial success (some records failed) |
| `401` | Invalid webhook secret |
| `500` | Handler error (exception during processing) |

```php
$webhookResult = $handler->handle($request);

// The result includes the HTTP status code
http_response_code($webhookResult->httpStatusCode);
echo json_encode($webhookResult->toResponseArray());
```

## Logging

The SDK uses PSR-3 logging. Pass any `LoggerInterface` implementation.

The SDK ships with `StderrLogger` — a simple logger that writes timestamped messages to stderr with `{key}` placeholder interpolation:

```php
use Daktela\CrmSync\Logging\StderrLogger;

$logger = new StderrLogger();
// Output: [2026-02-27 14:30:00] INFO: Syncing contact {id}

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger);
```

`SyncEngineFactory` uses `StderrLogger` by default. Pass your own logger (e.g. Monolog) to override:

```php
$factory = SyncEngineFactory::fromYaml('config/sync.yaml', logger: $monolog);
```

Log levels used:

| Level | When |
|-------|------|
| `info` | Batch sync completion summaries, relation map build stats |
| `warning` | Invalid webhook secrets, missing relation map fields |
| `error` | Per-record sync failures, webhook handling errors |
| `debug` | Entity not found during lookups |

## Debugging Tips

**Relation maps not resolving:**
- Ensure accounts are synced before contacts (use `fullSync()`)
- Check that your account mapping includes the `resolve_to` field (e.g., `name`)
- Check logs for "Cannot build relation map" warnings

**Records skipped unexpectedly:**
- In webhook sync, a `Skipped` status means the source entity wasn't found by ID
- Check that the ID in the webhook payload matches a valid CRM/CC record

**Transformer errors:**
- Ensure the transformer name in YAML matches a registered transformer
- Check that required params are provided (e.g., `date_format` needs `from` and `to`)
