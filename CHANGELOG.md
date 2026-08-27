# Changelog

## 1.4.0

Activity export is the focus of this release: it now refuses configurations that
would push a customer's contact-centre history without being asked, exports the
activity types it says it does, and never creates a CRM record it could have
found.

**A 1.x deployment can stop working on upgrade — read Breaking before rolling
this out.** Most breaks below are loud: a thrown exception or a reported step
failure on the first run. Two are silent and are marked as such.

### Breaking

- **`activity_types` is now required for an enabled activity entity.** Omitting it
  used to load fine and silently export nothing, forever, with the watermark
  advancing anyway. It is now rejected at config load.
  *Migration:* add `activity_types: [call, email, ...]` to the activity entity.

- **An activity export with `initial_sync: now` requires a `SyncStateStore`.**
  Without one there is no watermark to seed, so every run — not just the first —
  pushes the full contact-centre history to the CRM. `SyncEngine` now refuses it,
  and `SyncEngineFactory::fromYaml()` refuses to build without `$stateStorePath`.
  *Migration:* pass a `SyncStateStoreInterface` as `stateStore:` to `SyncEngine`,
  or `stateStorePath:` to `SyncEngineFactory::fromYaml()` — a writable path outside the config and release directories so it survives a redeploy.

- **`initial_sync` is new, and defaults to `now`.** 1.x had no such setting and
  always exported from the beginning of time. After upgrading, a first run seeds
  the watermark and pushes nothing.
  *Migration:* set `initial_sync: everything` to keep the old behaviour, or run
  `fullSync(forceFullSync: true)` once for a deliberate historical push.

- **Activity export always uses `upsertActivity()`, on both the batch and webhook
  paths.** It is now the only duplicate protection for activity export.
  *Adapter authors:* if your CRM has no activity-search endpoint, `upsertActivity()`
  must fall back to `createActivity()` rather than letting `findActivityByLookup()`
  throw — and be aware such a CRM accumulates a record per replayed window. Map the
  Daktela activity id into a CRM field and point `lookup_field` at it.

- **`ActivityType::Chat` now filters the API on `CHAT`, not `WEB`.** `web` is the
  webhook event prefix; the platform stores web chats as `CHAT`. A configured
  `activity_types: [web]` export matched nothing on every run in 1.x. It works
  now — **silent**: data starts flowing where none did, with no error to notice.
  Check your CRM before enabling it on an existing install.

- **Timezone names in `date_format` are validated at config load.** A typo in
  `from_tz`/`to_tz` used to fail only the records that carried a date, which made
  the batch partially successful and advanced the watermark past the records it
  dropped — permanent, silent loss. Bad zones are now rejected before the run.

- **Activity exports now report `Updated`, not `Created`.** **Silent.** 1.x decided
  this by comparing the Daktela activity id with the CRM record id — two different
  systems' identifiers, never equal — so it reported `Created` for effectively
  every activity, in-place updates included. Both paths now report `Updated`
  ("the CRM now matches"), because `upsertActivity()` does not say which branch it
  took. A host reading `SyncResult::getCreatedCount()` for activities sees it go
  from N to 0, and `getUpdatedCount()` from 0 to N.

- **`lookup_field` on an activity mapping must resolve to a per-record value in
  the mapped CRM payload.** The export checks this at write time and **aborts the
  step** if it does not — it is not a config-load check, because a loader can see
  that a rule targets the field but not that the rule fired for a given record.
  A missing value is always caught: `lookup_field` naming a `cc_field`, a rule
  living only under a `types:` block that did not apply, or a dotted path.

  A value that does not *vary* per record — a static rule, or a default that
  fires for everything — makes every activity resolve to the same CRM record and
  overwrite it. That is only caught when two activities read in the same drain
  share a value; it is **not** caught with `batch_size: 1`, on a quiet tenant
  where runs carry one activity, when the colliding records land in different
  drains, or on the webhook path at all. Verify per-record uniqueness yourself.

  It aborts rather than failing records one at a time on purpose: the fault
  applies to every record, and a per-record failure in a mixed-type batch is a
  partial failure, which advances the watermark past the refused records.

  The SDK's own example, quickstart and test fixtures shipped the first mistake
  (`lookup_field: name` against `crm_field: external_id`); all are corrected.
  *Migration:* point `lookup_field` at the CRM-side field carrying the Daktela id.
  Dotted paths are **not** supported for `lookup_field` — `Activity::get()` is
  flat, so a nested value cannot be read back.

### Added

- `ActivityType::InstagramDm` (`igdm`), on both the batch and webhook paths.
- Opt-in adapter capabilities, feature-detected via `instanceof` so existing
  adapters keep working without change: `SupportsCursorPaginationInterface` and
  `SupportsDealLinkingInterface`. (`SupportsCustomEntityWriteInterface` ships as a
  declared contract only — the engine does not consume it in this release, since
  custom-entity export is not part of it.)
- Per-activity-type field mapping (`default:` / `types:`), `value_map`, and
  declared-format date conversion.
- Step isolation: one failing entity no longer aborts the others, and
  `FullSyncResult::hasStepFailures()` reports it.
- Unroutable webhook events are logged as warnings instead of being answered
  `200` in silence.

### Removed

- **The activity idempotency ledger** (`SyncLedgerInterface`,
  `SyncLedgerLookupInterface`, `SyncEngine::setLedger()`) and
  `RecordNotFoundException`, which existed only to serve it. Never released — it
  was added and removed within this development cycle, so there is nothing to
  migrate from.

  It duplicated what `upsertActivity()` already does, and the two disagreed: the
  ledger's "already exported" skip suppressed legitimate updates, so an activity
  edited after close, or exported by a webhook before it closed, was frozen in the
  CRM at its earlier state. Its key was `(entityType, ccId)` with no
  per-integration dimension, so two integrations sharing a store would skip each
  other's records and hand each other the wrong CRM ids. If a CRM that genuinely
  cannot search activities needs it, it can return as an opt-in in a later minor,
  designed against a real adapter.

### Fixed

- A failed or empty API response is never read as "no records" (which advanced
  the watermark past data that was never fetched).
- The incremental activity window matches either `time_close` or `time`, so an
  activity that was postponed and later closed is not missed.

### Known limitations

- On a CRM that cannot search activities, `upsertActivity()` cannot dedupe. This
  is not limited to replayed windows: each webhook event of a multi-event call
  (`call_create` → `call_answer` → `call_close`) creates its own CRM record, so
  one call becomes three. The batch path's watermark bounds the damage there; the
  webhook path has no such bound. Either subscribe only to `*_close` on such a
  CRM, give the adapter a way to query by the Daktela activity id, or dedupe
  CRM-side.
- The check that `lookup_field` varies per record is best-effort: it only sees
  activities read in the same drain, and does not run on the webhook path. See
  Breaking, above.
- Custom-entity export (`cc_to_crm` custom entities) is not part of this release.
