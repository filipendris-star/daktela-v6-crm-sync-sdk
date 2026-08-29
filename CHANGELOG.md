# Changelog

## 1.2.0

Activity export is the focus of this release: it exports the activity types it
says it does, never creates a CRM record it could have found, and refuses
configurations that would push a customer's contact-centre history without being
asked.

**A v1.1.0 deployment upgrades in place.** Only one config change is required, and
only for deployments whose activity mapping came from the SDK's own example — see
Required migration. Everything else in this release is additive or is a fault the
config already had, now reported instead of hidden.

`tests/Regression/LegacyV110DeploymentTest.php` drives the frozen v1.1.0 example
config through the loader, the factory and a full sync on every CI run, so that
promise is enforced rather than asserted.

### Required migration

- **`lookup_field` on an activity mapping must name the CRM-side field.**
  The v1.1.0 example shipped `lookup_field: name` — a `cc_field` — against
  `crm_field: external_id`, so the export could never find the record it had
  written and created a duplicate CRM activity on every run. Every config derived
  from that example carries it.

  The mapping loader now rejects exactly that shape and names the value to use
  (`Did you mean "external_id"?`). It does **not** fail the whole config: the
  activity entity is disabled and reported, and contacts and accounts keep
  syncing.

  *Migration:* point `lookup_field` at the CRM-side field carrying the Daktela
  activity id. Dotted paths are **not** supported — `Activity::get()` is flat, so
  a nested value cannot be read back.

  A value that does not *vary* per record — a static rule, or a default that fires
  for everything — makes every activity resolve to the same CRM record and
  overwrite it. That is only caught when two activities read in the same drain
  share a value; it is **not** caught with `batch_size: 1`, on a quiet tenant, when
  the colliding records land in different drains, or on the webhook path at all.
  Verify per-record uniqueness yourself.

### Behaviour changes

Live for an existing deployment, but requiring no config change.

- **Only CLOSED activities are exported.** The Daktela query now filters on
  `action = CLOSE`. Closed activities are terminal, so one export is enough and no
  later update is needed — but an activity that never closes is now never
  exported. If you relied on open activities reaching your CRM, this changes what
  you receive.

- **`ActivityType::Chat` filters on `CHAT`, not `WEB`.** `web` is the webhook event
  prefix; the platform stores web chats as `CHAT`. A configured
  `activity_types: [web]` export matched nothing on every run in 1.1.0. It works
  now — **silent**: data starts flowing where none did, with no error to notice.
  Check your CRM before enabling it on an existing install. The config value stays
  `web`, so existing `activity_types` entries keep parsing.

- **Activity exports report `Updated`, not `Created`.** **Silent.** 1.1.0 decided
  this by comparing the Daktela activity id with the CRM record id — two different
  systems' identifiers, never equal — so it reported `Created` for effectively
  every activity, in-place updates included. Both paths now report `Updated`
  ("the CRM now matches"), because `upsertActivity()` does not say which branch it
  took. A host reading `SyncResult::getCreatedCount()` for activities sees it go
  from N to 0, and `getUpdatedCount()` from 0 to N. Sync behaviour is unchanged.

- **A per-entity config fault disables that entity instead of failing the load.**
  An unknown `activity_types` value, an empty `activity_types` on an enabled
  activity entity, an unknown `activity_type_map` key, an invalid `initial_sync`,
  an unloadable mapping file, or an unsupported custom-entity `direction` now
  disable *that entity* and are reported through
  `FullSyncResult::hasStepFailures()`. The rest of the sync runs.

  Several of these were silently tolerated in 1.1.0 — an unknown activity type was
  dropped, leaving the list empty, so the export reported an exhausted 0-record
  success and advanced the watermark on every run. They are now visible. A
  scheduler gating on `hasStepFailures()` will start seeing failures it did not see
  before, on configs that were already broken.

- **Timezone names in `date_format` are validated at load.** A typo in
  `from_tz`/`to_tz` used to fail only the records that carried a date, which made
  the batch partially successful and advanced the watermark past the records it
  dropped — permanent, silent loss.

- **`sync.batch_size` must be at least 1.** A smaller value degraded every drain to
  one record per batch.

### Added

- `initial_sync` (`now` / `everything`) on the activity entity: first-run behaviour
  when no watermark exists yet. `now` seeds the watermark to the current time and
  pushes nothing, so a first run does not flood the CRM with history; it requires a
  `SyncStateStore` (there is nothing to seed without one) and
  `SyncEngineFactory::fromYaml()` asks for `$stateStorePath`.

  **Omitting the key keeps the pre-1.2.0 behaviour** (`everything`) and logs a
  warning. The key is new, so no existing config can have opted into it, and
  reading its absence as `now` would impose the state-store requirement on
  deployments that never asked for it. **The default becomes `now` in 2.0** — set it
  explicitly.

- `ActivityType::InstagramDm` (`igdm`), on both the batch and webhook paths.
- Opt-in adapter capabilities, feature-detected via `instanceof` so existing
  adapters keep working unchanged: `SupportsCursorPaginationInterface` (contacts
  and accounts), `SupportsCustomEntityCursorPaginationInterface` (custom entities,
  which are read from the CRM and need the same treatment) and
  `SupportsDealLinkingInterface`.

  Custom-entity cursor paging is a separate interface on purpose: adding a third
  required method to `SupportsCursorPaginationInterface` would stop every adapter
  already implementing it from loading until updated — the break this release
  exists to stop repeating. Adopt it when convenient; without it, custom entities
  keep the offset path. The two may merge in 2.0.
- Per-activity-type field mapping (`default:` / `types:`), `value_map`, and
  declared-format, timezone-aware date conversion.
- Step isolation: one failing entity no longer aborts the others, and
  `FullSyncResult::hasStepFailures()` reports it.
- `SyncConfiguration::getEntityFaults()`: per-entity config faults, which
  `SyncEngine` seeds into its step failures.
- Unroutable webhook events are logged as warnings instead of being answered `200`
  in silence.

### Changed

- **Activity export always uses `upsertActivity()`**, on both the batch and webhook
  paths — as it did in 1.1.0. It is now the *only* duplicate protection for
  activity export.

  *Adapter authors:* if your CRM has no activity-search endpoint, `upsertActivity()`
  must fall back to `createActivity()` rather than letting `findActivityByLookup()`
  throw — and be aware such a CRM accumulates a record per replayed window.

- `find*` on `CrmAdapterInterface` must now distinguish absence from failure:
  return `null` only when the CRM answered and the record is genuinely not there,
  and throw when the answer could not be obtained. Relation resolution treats
  `null` as "nothing to resolve to" and passes the raw CRM key through, so an
  adapter that returns `null` during an outage writes a raw CRM id into a Daktela
  relation field and reports the record synced. Documentation only — no signature
  changed.

- The Daktela adapter flattens activity rows and derives nothing. `item_<field>` is
  exposed exactly as the platform returned it, including the direction casing,
  which varies by activity type (`in`/`out` for calls and emails, `IN`/`OUT` for
  the chat family).

  The `item_call_state` token an interim build derived from
  `item_direction` × `item_answered` is gone. Never released, so no deployment
  loses it — but a config written against this branch needs its rules moved. The
  capability did not disappear, it moved to where it belongs: map both source
  fields through and combine them in your adapter's `upsertActivity()`.
  docs/04, ["Deriving a Value From Two Daktela Fields"](docs/04-implementing-crm-adapter.md#deriving-a-value-from-two-daktela-fields),
  is a complete worked example — the YAML and the adapter code that turns the two
  fields into a CRM's `done`/`subject`/`type`, including the casing and `"0"`
  serialisation traps. `tests/Unit/Adapter/DerivedActivityStateExampleTest.php`
  executes that recipe, so it cannot rot.

  It was removed because its value vocabulary was chosen to feed one CRM's
  `value_map`: what a *combination* of Daktela fields means to a given CRM is that
  integration's business logic, and the shared platform adapter is the wrong place
  for it.

### Removed

- **The activity idempotency ledger** (`SyncLedgerInterface`,
  `SyncLedgerLookupInterface`, `SyncEngine::setLedger()`) and
  `RecordNotFoundException`. Never released — added and removed within this
  development cycle, so there is nothing to migrate from.

  It duplicated what `upsertActivity()` already does, and the two disagreed: the
  ledger's "already exported" skip suppressed legitimate updates, so an activity
  edited after close, or exported by a webhook before it closed, was frozen in the
  CRM at its earlier state. Its key was `(entityType, ccId)` with no
  per-integration dimension, so two integrations sharing a store would skip each
  other's records and hand each other the wrong CRM ids.

### Fixed

- A failed or empty API response is never read as "no records" (which advanced the
  watermark past data that was never fetched).
- The incremental activity window matches either `time_close` or `time`, so an
  activity that was postponed and later closed is not missed.
- Relation resolution runs on the webhook path, as it always did on the batch path.
  An account mapping carrying a `relation:` block got the raw CRM foreign key
  written into Daktela on the webhook path and the resolved value on the batch one.
- Activity pagination offsets are tracked per type. One shared offset was fed as
  the skip to every type's query, so whenever an earlier type contributed rows to a
  batch, the next batch started each remaining type past rows it never read.
- Relation resolution accepts integer foreign keys. Numeric-id CRMs hand back
  integers, and the previous `is_string()` guard silently skipped resolution for
  them, writing the raw CRM id into the Daktela relation field.
- A contact's owner email is resolved to a Daktela login once per upsert rather
  than twice, and a transient lookup failure no longer reports the contact as
  unchanged.

### Known limitations

- On a CRM that cannot search activities, `upsertActivity()` cannot dedupe. This is
  not limited to replayed windows: each webhook event of a multi-event call
  (`call_create` → `call_answer` → `call_close`) creates its own CRM record, so one
  call becomes three. The batch path's watermark bounds the damage; the webhook path
  has no such bound. Either subscribe only to `*_close` on such a CRM, give the
  adapter a way to query by the Daktela activity id, or dedupe CRM-side.
- The check that `lookup_field` varies per record is best-effort: it only sees
  activities read in the same drain, and does not run on the webhook path.
- Neither activity timestamp moves when an activity closes after being postponed,
  so one postponed before a run and closed after it falls outside every later
  window. Postponing applies to email, SMS and the chat channels, never to calls.
  A deployment that postpones heavily should schedule a periodic forced run.
- Custom-entity export (`cc_to_crm` custom entities) is not implemented.
  `SupportsCustomEntityWriteInterface` ships as a compatibility declaration only —
  the engine never calls it, and the config loader faults any enabled entry that
  declares the direction.
