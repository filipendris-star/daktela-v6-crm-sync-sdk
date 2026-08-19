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
      - cc_field: item_call_state
        crm_field: done
        transformers:
          - { name: value_map, params: { map: { in_missed: 0 }, default: 1 } }
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

**Flattened activity fields.** The Daktela adapter flattens each activity's
nested relations so rules can address them as scalars: `user_email`,
`user_login`, `user_title`, `contact_name`, and `item_<field>` for every
scalar field of the type-specific item record (`item_direction`,
`item_answered`, `item_text`, ...). For call activities it also derives
**`item_call_state`** from direction + answered — one token
(`out_answered`, `out_noanswer`, `in_answered`, `in_missed`,
`internal_answered`, `internal_noanswer`) that a single `value_map` can turn
into a CRM `done` flag or subject, which two separate fields cannot express.

**`lookup_field` addresses different sides per direction.** On import
(`crm_to_cc`) the upsert looks up the *CC-side* record, so `lookup_field`
names a CC field. On export (`cc_to_crm`, incl. custom entities) the
existence check runs against the *mapped CRM payload*, so it must name the
CRM-side field your mapping writes (dotted paths into custom fields work).
A mapping file copied from an import and flipped to export usually needs its
`lookup_field` changed, or every run creates duplicates.

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
5. If a value still cannot be resolved (entity not found in CRM), the original value is passed through unchanged

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
from the current time). `to_tz` only applies when the `from` format carries
time-of-day (`H`/`G`/`h`/`g`/`i`/`s`/`v`/`u`/`U`): a date is not an instant, so for
date-only formats the conversion is skipped and the date passes through
unshifted instead of moving a day backward east of UTC.

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

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, $registry);
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

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, $registry);
```

```yaml
transformers:
  - name: currency
    params: { from: CZK, to: EUR }
```
