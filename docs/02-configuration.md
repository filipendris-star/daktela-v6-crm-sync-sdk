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
| `batch_size` | int | 100 | Max records per batch sync run per entity type. For `cc_to_crm` custom-entity exports the effective batch is additionally capped at the adapter page size (100) — see the write_back pagination note under `sync.custom_entities[]` |

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
| `initial_sync` | string | `now` (default) — first run seeds the cursor and skips history; `everything` — first run exports all historical records |
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

### `sync.custom_entities[]`

Optional list of extra sync slots for arbitrary CRM-side resources (the
`target` is adapter-interpreted, e.g. a REST path). `cc_to_crm` entries need an
adapter implementing `SupportsCustomEntityWriteInterface`.

| Key | Type | Description |
|-----|------|-------------|
| `name` | string | Unique slot name (required) |
| `enabled` | bool | Default `false` |
| `direction` | string | `crm_to_cc` or `cc_to_crm` (required) |
| `source` | string | CC-side entity (`contact`, `account`) (required) |
| `target` | string | CRM-side resource name/path (required) |
| `mapping_file` | string | Mapping file for the slot |
| `initial_sync` | string | `now` (default) or `everything` |
| `export_filter` | list | `{field, operator, value}` conditions selecting which CC records export |
| `write_back` | list | Inline mapping rules applied CRM→CC after create (typically stamps the prefixed CRM id back, which removes the record from the export filter) |

The write_back rules **must** rewrite a field the `export_filter` checks (the
documented convention: rename the lookup field with the CRM-id prefix). The
export pagination relies on successful write-backs removing records from the
filtered set; a write_back that only touches unrelated fields is detected at
runtime and logged as a warning, but records will be re-processed once before
the engine advances past them.

```yaml
sync:
  custom_entities:
    - name: contact_export
      enabled: true
      direction: cc_to_crm
      source: contact
      target: persons
      mapping_file: mappings/contact_export.yaml
      export_filter:
        - { field: name, operator: notlike, value: 'pipedrive_person_%' }
      write_back:
        - cc_field: name
          crm_field: id
          transformers:
            - { name: prefix, params: { value: pipedrive_person_ } }
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
