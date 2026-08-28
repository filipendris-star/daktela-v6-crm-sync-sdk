# Configuration

All configuration is done via YAML files. The main config file references per-entity mapping files.

## Main Configuration (`sync.yaml`)

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

## Configuration Reference

### `daktela`
| Key | Type | Description |
|-----|------|-------------|
| `instance_url` | string | Your Daktela instance URL (e.g., `https://acme.daktela.com`) |
| `access_token` | string | API access token (create in Daktela: Manage → Users → API tokens) |
| `database` | string | Database/segment for Contacts & Accounts (e.g., `default`) |

### `sync`
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `batch_size` | int | 100 | Max records per batch sync run per entity type |

### `sync.entities.<type>`

Each entity type (`contact`, `account`, `activity`) can be configured independently:

| Key | Type | Description |
|-----|------|-------------|
| `enabled` | bool | Whether this entity sync is active |
| `direction` | string | `crm_to_cc`, `cc_to_crm`, or `bidirectional` |
| `mapping_file` | string | Path to YAML mapping file (relative to config dir) |
| `activity_types` | array | For activities only: which types to sync |
| `activity_type_map` | map | For activities only: CC type → CRM activity type key (supports CRM-side custom types, e.g. `sms: sms`). The SDK loader validates the CC type keys; the map itself is consumed by the adapter *factory* (e.g. `PipedriveSyncEngineFactory` reads it from the raw config and passes it to the adapter) — the SDK core does not read it |
| `link_deal` | string | For activities only: deal-linking strategy (e.g. `latest_open`) — requires an adapter implementing `SupportsDealLinkingInterface` |
| `initial_sync` | string | `now` — first run seeds the watermark and skips history; `everything` — first run exports all historical records. **Omitting the key behaves as `everything`** and logs a warning: the setting is new in 1.2.0, so a config written earlier never chose it, and defaulting those to `now` would impose the state-store requirement below on deployments that never asked for it. **The default becomes `now` in 2.0 — set it explicitly.** `now` **requires a state store**: without one there is no watermark to seed, so the activity export refuses to run rather than push full history on every run. `everything` runs without a state store but re-pushes on every run, so it warns. Note a reset does **not** undo `now`: see [Reset State](09-production-deployment.md#reset-state) |
| `auto_create_contact` | object | Auto-create a contact from account data (see [Sync Engine](05-sync-engine.md#auto-create-contact-from-account)) |

### Activity Types

Available activity types for the `activity_types` config:

| Value | Channel |
|-------|---------|
| `call` | Phone calls |
| `email` | Emails |
| `web` | Web chat |
| `sms` | SMS messages |
| `fbm` | Facebook Messenger |
| `wap` | WhatsApp |
| `vbr` | Viber |
| `igdm` | Instagram Direct |

These values come from the webhook **event-prefix** namespace, which is not
always the value the platform's API stores: `web` is `CHAT` on the API side.
The SDK translates (`ActivityType::apiValue()`); configure the value in this
table.

**Only CLOSED activities are exported**, and an activity that never closes is
never exported. Webhooks are the path for pushing one earlier in its life.

The incremental window matches **either `time_close` or `time`**, because
activities have no `edited` field and neither timestamp is a reliable change
marker on its own:

- `time` is the start, so it misses an activity that began before the watermark
  and closed after it.
- `time_close` misses one whose close time is older than its close *event*: the
  platform writes `time_close` when an activity is postponed and then leaves it
  alone when the activity finally closes, so a postponed-then-closed activity
  carries a stale value. (Postponing applies to email, SMS and the chat channels —
  never to calls.) A back-dated custom activity has the same shape.

Matching on either covers strictly more than `time` alone did, and the
adapter's upsert dedupes the overlap.

**It is not complete.** Neither timestamp moves when an activity *closes* after
being postponed, so an activity postponed before a run and closed after it has
both fields behind the watermark and is never exported by any later run — the
loss is silent and permanent, and only a forced run recovers it. This affects the
postponable channels (email, SMS, chat), never calls. There is no field that
would fix it: activities carry no `edited`, and `action` becoming `CLOSE` is not
a timestamp. If your deployment postpones heavily, schedule a periodic
`fullSync(forceFullSync: true)`.

### `sync.custom_entities[]`

Optional list of extra sync slots that import an arbitrary CRM-side resource
into a first-class Daktela entity (`crm_to_cc` only in this release; the
`target` is adapter-interpreted, e.g. a REST path).

| Key | Type | Description |
|-----|------|-------------|
| `name` | string | Unique slot name (required) — also the state key (`custom:<name>`) |
| `enabled` | bool | Default `false` |
| `direction` | string | `crm_to_cc` (required) |
| `source` | string | CRM-side resource name/path (required) |
| `target` | string | Daktela entity to write (`contact`, `account`) (required) |
| `mapping_file` | string | Mapping file for the slot |

```yaml
sync:
  custom_entities:
    - name: leads
      enabled: true
      direction: crm_to_cc
      source: leads
      target: contact
      mapping_file: mappings/leads.yaml
```

### `webhook`
| Key | Type | Description |
|-----|------|-------------|
| `secret` | string | Shared secret for webhook validation (set in Daktela automation headers) |

## Environment Variables

Use `${ENV_VAR}` syntax to reference environment variables. This keeps secrets out of YAML files:

```yaml
daktela:
  access_token: "${DAKTELA_ACCESS_TOKEN}"

webhook:
  secret: "${WEBHOOK_SECRET}"
```

The loader resolves these at load time using `getenv()`. If the environment variable is not set, the raw `${...}` string is kept.

Environment variable resolution also works in mapping YAML files (not just `sync.yaml`). Inline interpolation is supported — `"prefix${VAR}suffix"` resolves the variable while keeping the surrounding text. This is useful for URL templates:

```yaml
# In a mapping file
transformers:
  - name: url
    params: { template: "https://crm.example.com/${CRM_INSTANCE}/?entity=Person&id={value}" }
```

Here `${CRM_INSTANCE}` resolves at config load time, while `{value}` is a transformer placeholder replaced at runtime.

## Loading Configuration

```php
use Daktela\CrmSync\Config\YamlConfigLoader;

$config = (new YamlConfigLoader())->load(__DIR__ . '/config/sync.yaml');

// Access values
$config->instanceUrl;     // "https://your-instance.daktela.com"
$config->accessToken;     // resolved from env var
$config->database;        // "default"
$config->batchSize;       // 100
$config->webhookSecret;   // resolved from env var

// Entity configs
$config->isEntityEnabled('contact');           // true
$config->getEntityConfig('contact');           // EntitySyncConfig
$config->getEntityConfig('contact')->direction; // SyncDirection::CrmToCc

// Mappings (loaded from referenced YAML files)
$config->getMapping('contact');  // MappingCollection
$config->getMapping('account');  // MappingCollection
```

## Mapping File Schema

Each mapping file defines how fields are translated. See [Field Mapping](03-field-mapping.md) for full reference.

Minimal example:

```yaml
entity: contact
lookup_field: email
mappings:
  - cc_field: title           # Daktela field
    crm_field: full_name      # CRM field
  - cc_field: email
    crm_field: email
```

Extended with all features:

```yaml
entity: contact
lookup_field: email
mappings:
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
  - cc_field: customFields.tags
    crm_field: tags
    multi_value:
      strategy: split
      separator: ","
```
