# Field Mapping

Field mappings define how data is translated between Daktela CC fields and CRM fields. Each entity type (contact, account, activity) has its own YAML mapping file.

## YAML Schema

```yaml
entity: contact          # Entity type
lookup_field: email      # Field used for upsert lookups (see note below)
mappings:
  - cc_field: title      # Daktela CC field name
    crm_field: full_name # CRM field name
    transformers:        # Optional value transformers
      - name: string_case
        params: { case: title }
    multi_value:         # Optional multi-value handling
      strategy: join
      separator: ", "
    relation:            # Optional cross-entity reference
      entity: account
      resolve_from: id
      resolve_to: name
```

## Per-Activity-Type Rules (`default` / `types`)

Activity mapping files support a structured form: shared rules under
`default:`, per-type overrides under `types:` keyed by activity type
(`call`, `sms`, `email`, ...). Use either this structure or the legacy
top-level `mappings:` — not both. Empty keys (`mappings: {}`, `default: {}`)
emitted by config UIs are tolerated and treated as absent.

```yaml
entity: activity
lookup_field: externalId
default:
  mappings:
    - { cc_field: name, crm_field: externalId }
    - { cc_field: title, crm_field: subject }
types:
  call:
    mappings:
      - cc_field: item_direction
        crm_field: direction
        transformers:
          - { name: value_map, params: { map: { in: inbound, out: outbound, IN: inbound, OUT: outbound } } }
      - { cc_field: item_answered, crm_field: answered }
  sms:
    mappings:
      - { cc_field: item_text, crm_field: note }
```

**Merge semantics** (`MappingCollection::forType()`): a non-append type rule
*replaces* the non-append default rule targeting the same output field, in
place; anything else is appended. `append: true` rules are never deduped —
they exist to accumulate several values into one field, so both default and
type append rules always survive the merge. Types without rules (and unknown
types) get the default rules unchanged.

**Every synced type must end up with at least one rule.** Because `default:` may
be absent, a file declaring only `types:` gives an activity type that is missing
from that map an *empty* rule set — a type listed in `activity_types` but not in
`types:`, with no `default:` block, maps to nothing at all. The engine refuses to
write that empty payload: the record fails with
`Mapping for activity type "sms" produced an empty payload`, on the batch and
webhook paths alike. Failing is deliberate — creating the blank CRM record
instead would advance the watermark past it, permanently
and without retry. Add a `default:` block, or a `types:` entry per synced type;
once the mapping is fixed, the next run exports the records properly.

**Flattened activity fields.** The Daktela adapter flattens each activity's
nested relations so rules can address them as scalars: `user_email`,
`user_login`, `user_title`, `contact_name`, and `item_<field>` for every
scalar field of the type-specific item record (`item_direction`,
`item_answered`, `item_text`, ...). `item` is type-specific, so which `item_*`
fields exist differs per activity type.

Flattening only: every field is one the platform returned, with the value it
returned. Nothing is derived, combined or normalised — including the direction,
which the platform stores lowercase (`in`/`out`) for calls and emails but
uppercase (`IN`/`OUT`) for the chat family. A `value_map` that lists only one
case sends the rest to its default.

**Deriving values the mapping engine cannot express.** A rule reads one source
field, and a transformer sees only that scalar, so a value combining *two* fields
— a call state from `item_direction` × `item_answered`, say — cannot be produced
by a mapping rule.

Do it in your CRM adapter, not in the SDK. Map both source fields through to the
payload and combine them in `upsertActivity()`. What a combination of Daktela
fields *means* to a particular CRM is that CRM's concern: the SDK ships the
platform's data faithfully and stays out of the interpretation, so no one CRM's
vocabulary ends up baked into the shared adapter.

See [Deriving a Value From Two Daktela Fields](04-implementing-crm-adapter.md#deriving-a-value-from-two-daktela-fields)
for a worked example — the YAML that passes both fields through, and the adapter
code that turns them into a CRM's `done`/`subject`/`type`.

**`lookup_field` addresses different sides per direction.** On import
(`crm_to_cc`) the upsert looks up the *CC-side* record, so `lookup_field`
names a CC field. On export (`cc_to_crm`) the
existence check runs against the *mapped CRM payload*, so it must name the
CRM-side field your mapping writes, and that field must carry a per-record
value. A mapping file copied from an import and flipped to export usually needs
its `lookup_field` changed.

**A missing lookup value is always refused.** If the mapped payload carries no
value at `lookup_field` — because it names a `cc_field`, because the only rule
writing it lives under a `types:` block that did not apply, or because it is a
dotted path (the mapper writes `crm_field: custom.daktela_id` as a nested array,
and the lookup reads the payload flat) — the batch export aborts the activity
step. It aborts rather than failing that record, so the watermark is held and
nothing falls outside the next window.

**A non-varying lookup value is only *sometimes* detected — do not rely on it.**
A static rule, or a default that fires for every activity, produces one value for
everything, so every activity resolves to the same CRM record and overwrites the
last. The export refuses this when it can see it: when two activities read in the
same drain share a value. It cannot see it when the drain holds a single record
(`batch_size: 1`, or a quiet tenant where each incremental run picks up one
activity), when the colliding activities land in different drains, or on the
webhook path at all — that path handles one event and keeps no memory between
them.

So: **check yourself that `lookup_field` names a field carrying a distinct value
per activity.** The Daktela activity `name` mapped into a CRM field is the
reliable choice. The SDK will catch the obvious mistakes, not all of them.

## Direction

Sync direction is configured at the **entity level** in `sync.yaml`, not per field. All field mappings within an entity follow the entity's direction. The mapping engine automatically reads from the correct side based on direction:

- `crm_to_cc` — reads CRM fields (`crm_field`), writes CC fields (`cc_field`)
- `cc_to_crm` — reads CC fields (`cc_field`), writes CRM fields (`crm_field`)

## Dot Notation for Nested Fields

Access nested fields (such as Daktela's `customFields`) using dots:

```yaml
- cc_field: customFields.industry
  crm_field: industry
```

This reads/writes `$entity['customFields']['industry']`. Intermediate arrays are created automatically when writing.

You can also nest on the CRM side:

```yaml
- cc_field: email
  crm_field: contact_info.email
```

---

## Multi-Value Custom Fields

Daktela custom fields can store multiple values as arrays (e.g., tags, categories, interests). The `multi_value` config controls how array values are converted between systems.

### Strategies

| Strategy | Direction hint | Description |
|----------|---------------|-------------|
| `as_array` | Both | Keep value as an array, wrap scalars in `[]` |
| `join` | Array → String | Join array elements with separator into a string |
| `split` | String → Array | Split a string by separator into an array |
| `first` | Array → Scalar | Take the first element of an array |
| `last` | Array → Scalar | Take the last element of an array |

### Examples

**CRM stores tags as comma-separated string, Daktela stores as array:**

```yaml
# CRM "web,mobile,api" → Daktela ["web", "mobile", "api"]
- cc_field: customFields.tags
  crm_field: tags
  multi_value:
    strategy: split
    separator: ","
```

**Daktela stores interests as array, CRM wants a joined string:**

```yaml
# Daktela ["sports", "music"] → CRM "sports, music"
- cc_field: customFields.interests
  crm_field: interests
  multi_value:
    strategy: join
    separator: ", "
```

**Take only the first value from a multi-value field:**

```yaml
- cc_field: customFields.disposition
  crm_field: primary_disposition
  multi_value:
    strategy: first
```

**Pass arrays as-is (both systems support arrays):**

```yaml
- cc_field: customFields.categories
  crm_field: categories
  multi_value:
    strategy: as_array
```

### Processing Order

For each field mapping, processing happens in this order:
1. Read source value
2. Apply transformer chain
3. Resolve relations
4. Apply multi-value strategy (non-append fields only)
5. Write to target (append or set)

For `append` fields, the `multi_value` strategy is deferred — it runs once after all values for that target field are accumulated. This allows `multi_value: join` to collapse the final array into a string.

---

## Relations (Cross-Entity References)

When syncing contacts, you often need to resolve references to other entities. For example, a CRM contact has a `company_id` that references a CRM account, but Daktela's `account` field expects the Daktela account `name`.

### Configuration

```yaml
- cc_field: account          # Daktela CC field
  crm_field: company_id      # CRM field
  relation:
    entity: account        # The related entity type
    resolve_from: id       # Match CRM account by this field
    resolve_to: name       # Use this Daktela field as the resolved value
```

### How It Works

1. During `fullSync()`, accounts are synced first
2. The engine builds a resolution map: `CRM account.id → Daktela account.name`
3. When syncing contacts, the mapper sees `company_id = "crm-acc-123"` and resolves it to `account = "acme"` using the map
4. If a referenced entity is missing from the map, the engine auto-fetches it from the CRM and syncs it on-the-fly (with recursion protection)
5. If the entity does not exist in the CRM, the original value is passed through unchanged
6. If the entity exists but syncing it **failed** (CRM unreachable, Daktela write rejected), the referencing record fails instead

Two cases fall outside 5 and 6 and still pass the value through: no mapping is
configured for the referenced entity type, and the recursion guard declining a
reference already being resolved higher up the stack. Neither attempted a
resolution, so neither is reported as a failure.

Steps 5 and 6 look the same from the mapper's side and must not be treated the
same. Passing an unresolved value through is only correct when there is genuinely
nothing to resolve to; doing it after a *failed* attempt writes a raw CRM foreign
key into a Daktela relation field and reports the record as synced, so the
watermark advances past a wrong link no later run revisits.

Failing the individual record instead keeps this scale-free: unaffected records
in the same batch keep syncing.

Be aware of what a failed record does and does not get you. It is reported in
`SyncResult` and it is *not* written with a bad link — but it is not retried
automatically either: the watermark still advances as long as some record
succeeded (see [Error Handling](07-error-handling.md)), so the record stays
outside the incremental window until its source timestamp changes again or a
forced full sync runs. The trade is "absent and reported" over "present and
silently wrong". If a host needs the stronger guarantee, it should watch
`SyncResult` for failed records and re-drive them.

Steps are not gated on each other for this — gating the whole contact step on
"the account step failed" stalls every contact for as long as one account is
broken, and still misses a *partial* account failure, which is the case that
actually produces unresolved references.

### Using fullSync()

The `SyncEngine::fullSync()` method handles the correct dependency order automatically:

```php
$results = $engine->fullSync();

// $results['account'] — SyncResult for accounts
// $results['contact'] — SyncResult for contacts (with resolved account refs)
// $results['activity'] — SyncResult for activities
```

Syncing accounts before contacts is still recommended for efficiency, but not strictly required — missing accounts are auto-created on-the-fly:

```php
$engine->syncAccountsBatch(); // Recommended first for efficiency
$engine->syncContactsBatch(); // Resolves account references; auto-syncs missing accounts
```

---

## Built-in Transformers

### `date_format`
Converts between date formats using PHP's `DateTimeImmutable`, with optional
timezone conversion.

```yaml
transformers:
  - name: date_format
    params:
      from: "Y-m-d H:i:s"   # Source format
      to: "c"                # Target format (ISO 8601)
      from_tz: "Europe/Prague"  # Optional: timezone the input wall-time is in
                                # (default: the process/instance timezone)
      to_tz: "UTC"              # Optional: convert to this zone before formatting
```

If the source value doesn't match the `from` format, the transformer attempts generic parsing as a fallback.

**Timezone conversion.** The Daktela v6 API returns naive local datetimes. When
the target CRM interprets times as UTC (e.g. Pipedrive `due_date`/`due_time`),
set `to_tz: UTC` so the instant is shifted before formatting — otherwise
activities land offset by the local UTC offset. Named zones handle DST per
date: a Prague summer time converts at +2 h, a winter time at +1 h.

```yaml
# Pipedrive due_date/due_time from one CC timestamp — both convert as one instant
- cc_field: time
  crm_field: due_date
  transformers:
    - { name: date_format, params: { from: 'Y-m-d H:i:s', to: 'Y-m-d', to_tz: 'UTC' } }
- cc_field: time
  crm_field: due_time
  transformers:
    - { name: date_format, params: { from: 'Y-m-d H:i:s', to: 'H:i', to_tz: 'UTC' } }
```

Fields the `from` format leaves unspecified are anchored to zero (not filled
from the current time).

**`to_tz` converts what `from` declares.** Two conditions, both read off the
config: `from` must parse a time of day, and the value must actually match it.
Nothing about the value is inspected or guessed.

The defaults are Daktela's own shape — `from: 'Y-m-d H:i:s'`, which is what the
v6 API emits for every `ItemDescription::DateTime` field — so the common CC→CRM
case needs no extra configuration beyond the target zone.

For anything else, **declare the format the source emits** and it converts:

| Source shape | `from` |
|---|---|
| `2024-06-01 14:30:00` (Daktela) | `Y-m-d H:i:s` (default) |
| `2024-06-01T14:30:00+02:00` or `…Z` | `Y-m-d\TH:i:sP` |
| `1717245000` (unix epoch) | `U` |
| `01.06.2024 14:30:00` | `d.m.Y H:i:s` |

Two rules follow, and they are the whole of the behaviour:

- **A value that does not match `from`** is still parsed generically and
  reformatted (legacy behaviour), but never zone-shifted. If timestamps come out
  unconverted, `from` does not describe the data — fix `from`.
- **A date-only `from`** (`Y-m-d`) never converts, whatever an individual value
  contains. A date is not an instant: shifting `2026-08-19` into UTC would emit
  `2026-08-18` on any instance east of UTC while silently "working" west of it.

This is declared rather than detected on purpose. Whether an arbitrary value is
an instant is not decidable — PHP's parser reports hour, minute and second all
zero for both `today` and a real midnight, so a calendar date and a genuine
midnight are indistinguishable after parsing. `from` already carries the answer.

### `phone_normalize`
Strips all non-digit/non-plus characters and optionally prepends `+` for E.164 format.

```yaml
transformers:
  - name: phone_normalize
    params: { format: e164 }
```

Example: `"(420) 123-456-789"` → `"+420123456789"`

### `boolean`
Casts to boolean. Recognizes these string values as truthy: `"true"`, `"yes"`, `"1"`, `"on"` (case-insensitive).

```yaml
transformers:
  - name: boolean
```

### `string_case`
Changes string case. Supported values for `case` param: `lower`, `upper`, `title`.

```yaml
transformers:
  - name: string_case
    params: { case: lower }
```

### `default_value`
Provides a fallback when the source value is `null`.

```yaml
transformers:
  - name: default_value
    params: { value: "N/A" }
```

### `callback`
Runs a registered PHP closure. Register callbacks on the `CallbackTransformer` before creating the engine:

```php
$registry = TransformerRegistry::withDefaults();
$callback = $registry->get('callback');
assert($callback instanceof CallbackTransformer);
$callback->registerCallback('normalize_country', function (mixed $value): string {
    return match (strtolower((string) $value)) {
        'cz', 'czech republic', 'czechia' => 'CZ',
        'sk', 'slovakia' => 'SK',
        default => strtoupper((string) $value),
    };
});

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, $registry,
    stateStore: new FileSyncStateStore('var/sync-state.json'));
```

```yaml
transformers:
  - name: callback
    params: { name: normalize_country }
```

### `prefix`
Prepends a string to the value. Useful for creating unique IDs with CRM-specific prefixes.

```yaml
transformers:
  - name: prefix
    params: { value: "crm_" }
```

Example: `"12345"` → `"crm_12345"`

Null and empty string values are returned unchanged.

### `strip_prefix`
Removes a prefix from the beginning of a string. The inverse of `prefix` — useful for extracting the original ID from a prefixed value.

```yaml
transformers:
  - name: strip_prefix
    params: { value: "crm_" }
```

Example: `"crm_12345"` → `"12345"`. If the value doesn't start with the prefix, it is returned unchanged.

### `value_map`
Maps discrete input values to configured outputs. YAML keys are strings —
booleans match as `"true"`/`"false"`, null as `"null"`. Without `default`,
unmatched input passes through unchanged.

```yaml
# Derive Pipedrive activity "done" from the derived call state:
transformers:
  - name: value_map
    params:
      map: { in_missed: 0 }   # missed inbound call → open/overdue task
      default: 1              # everything else → completed
```

### `wrap_array`
Wraps a scalar value in an array. Already-array values are returned as-is, null/empty values become `[]`. Useful when Daktela expects array custom fields but the CRM provides a single value.

```yaml
transformers:
  - name: wrap_array
```

Example: `"john@example.com"` → `["john@example.com"]`

### `url`
Builds a URL from a template with a `{value}` placeholder. Useful for generating CRM detail links stored in Daktela's description field.

```yaml
transformers:
  - name: url
    params: { template: "https://crm.example.com/contact/{value}" }
```

Example with value `"42"`: → `"https://crm.example.com/contact/42"`

The template supports `${ENV_VAR}` placeholders (resolved at config load time), so you can use instance-specific URLs:

```yaml
transformers:
  - name: url
    params: { template: "https://crm.example.com/${CRM_INSTANCE}/?entity=Person&id={value}" }
```

### `join`
Joins an array value into a string. Filters out null and empty values before joining.

```yaml
transformers:
  - name: join
    params: { separator: " " }   # default separator is a space
```

Example: `["John", "Doe"]` → `"John Doe"`. Strings are passed through unchanged.

## Combining Multiple Fields with Append

Use `append: true` to collect multiple source fields into an array, then `multi_value: join` to collapse it into a string. The `multi_value` strategy on append fields runs after all values are accumulated.

```yaml
# Map firstName + lastName → title (e.g. "Kristýna Kovandová")
- crm_field: firstName
  cc_field: title
  append: true

- crm_field: lastName
  cc_field: title
  append: true
  multi_value:
    strategy: join
    separator: " "
```

Without `multi_value`, the result would be an array `["Kristýna", "Kovandová"]`. With `multi_value: join`, it collapses to `"Kristýna Kovandová"`.

## Transformer Chains

Multiple transformers are applied in sequence:

```yaml
transformers:
  - name: default_value
    params: { value: "unknown" }
  - name: string_case
    params: { case: upper }
```

This first fills `null` values with `"unknown"`, then uppercases the result.

## Environment Variables in Mapping Files

Mapping YAML files support the same `${ENV_VAR}` syntax as `sync.yaml`. This is resolved at config load time by `EnvResolver`. Inline interpolation works too: `"prefix${VAR}suffix"`.

This is particularly useful for URL templates that contain instance-specific values:

```yaml
transformers:
  - name: url
    params: { template: "https://crm.example.com/${CRM_INSTANCE}/?entity=Person&id={value}" }
```

At load time, `${CRM_INSTANCE}` is replaced with the environment variable value, while `{value}` is a transformer placeholder replaced at runtime.

## Custom Transformers

Implement `ValueTransformerInterface` and register it:

```php
use Daktela\CrmSync\Mapping\Transformer\ValueTransformerInterface;

class CurrencyTransformer implements ValueTransformerInterface
{
    public function getName(): string { return 'currency'; }

    public function transform(mixed $value, array $params = []): mixed
    {
        $from = $params['from'] ?? 'CZK';
        $to = $params['to'] ?? 'EUR';
        // your conversion logic
        return $convertedValue;
    }
}

$registry = TransformerRegistry::withDefaults();
$registry->register(new CurrencyTransformer());

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, $registry,
    stateStore: new FileSyncStateStore('var/sync-state.json'));
```

```yaml
transformers:
  - name: currency
    params: { from: CZK, to: EUR }
```
