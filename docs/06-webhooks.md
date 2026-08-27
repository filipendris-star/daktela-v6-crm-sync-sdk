# Webhooks

The SDK includes a PSR-7 compatible webhook handler for real-time sync triggers from Daktela.

## Setup

```php
use Daktela\CrmSync\Webhook\WebhookHandler;
use Daktela\CrmSync\Webhook\WebhookPayloadParser;

$handler = new WebhookHandler(
    syncEngine: $engine,
    parser: new WebhookPayloadParser(),
    webhookSecret: $config->webhookSecret,
    logger: $logger,
);
```

## Handling Requests

The handler accepts a PSR-7 `ServerRequestInterface`:

```php
// In your HTTP controller / route handler:
$result = $handler->handle($request);

http_response_code($result->httpStatusCode);
header('Content-Type: application/json');
echo json_encode($result->toResponseArray());
```

---

## Setting Up Daktela Webhook Events

Daktela V6 uses **Automations** (also called Triggers/Hooks) to send HTTP notifications when events occur. Here's how to configure them:

### Step 1: Access Automations

1. Log in to your Daktela instance admin panel
2. Navigate to **Manage** → **Automations** (or **Settings** → **Automations** depending on version)
3. Click **Create New Automation**

### Step 2: Configure the Trigger

For each entity type you want to sync in real-time, create a separate automation:

**For Activities (Daktela → CRM):**

| Setting | Value |
|---------|-------|
| Name | `CRM Sync - Call Close` |
| Event | `After call close` (`call_close`) |
| Enabled | Yes |

Repeat for other activity types:
- `After email create` (`email_create`)
- `After chat close` (`web_close`)
- `After SMS create` (`sms_create`)
- `After Facebook message create` (`fbm_create`)
- `After WhatsApp message create` (`wap_create`)
- `After Viber message create` (`vbr_create`)
- `After Instagram message create` (`igdm_create`)

**For Contacts (CRM → Daktela via Daktela-initiated events):**

If you also want Daktela to notify your CRM when contacts are modified in Daktela:

| Setting | Value |
|---------|-------|
| Name | `CRM Sync - Contact Update` |
| Event | `After contact update` (`contact_update`) |

### Step 3: Configure the Action

For each automation, add an **HTTP Request** action:

| Setting | Value |
|---------|-------|
| Action Type | HTTP Request (Webhook) |
| URL | `https://your-app.example.com/webhook/daktela` |
| Method | POST |
| Content-Type | `application/json` |
| Headers | `X-Webhook-Secret: your-secret-here` |

### Step 4: Configure the Payload

Set the JSON body template. Daktela uses template variables to include event data:

```json
{
  "event": "{{event_name}}",
  "name": "{{object_name}}",
  "data": {
    "title": "{{title}}",
    "contact": "{{contact}}",
    "direction": "{{direction}}",
    "time_start": "{{time_start}}",
    "time_close": "{{time_close}}"
  }
}
```

The exact template variables depend on the entity type and your Daktela version. Consult your Daktela documentation for available variables.

### Recommended Automations

For a typical CRM integration, create these automations:

| Automation | Event | Purpose |
|-----------|-------|---------|
| Call sync | `call_close` | Sync completed calls to CRM |
| Email sync | `email_create` | Sync emails to CRM |
| Chat sync | `web_close` | Sync completed chats to CRM |

**Do not point `contact_update` / `account_update` automations at
`WebhookHandler`.** Contacts and accounts flow CRM → Daktela, and the handler's
`contact`/`account` arms call `syncContact($id)` / `syncAccount($id)`, which look
`$id` up **in the CRM**. A Daktela automation sends the *Daktela* record name — so
under the import naming convention the handler asks the CRM for
`pipedrive_person_123`, finds nothing, and reports `Skipped` on every event.

Those arms exist for the opposite direction: a **CRM-side** webhook telling
Daktela that a CRM record changed, where the id genuinely is a CRM id. Wiring
that up is host glue, since every CRM's webhook payload differs — see
[Production Deployment](09-production-deployment.md) for the shape.

---

## Event Name → Entity Type Mapping

The `WebhookPayloadParser` maps Daktela event name prefixes to entity types:

| Event Prefix | Entity Type | Activity Type | Example Events |
|-------------|-------------|---------------|----------------|
| `contact` | contact | — | `contact_create`, `contact_update`, `contact_delete` — but see the warning above: the id must be a **CRM** id, so these are for CRM-side webhooks, not Daktela automations |
| `account` | account | — | `account_create`, `account_update`, `account_delete` — same caveat |
| `call` | activity | Call | `call_create`, `call_answer`, `call_close` |
| `email` | activity | Email | `email_create`, `email_close` |
| `web` | activity | Chat | `web_create`, `web_close` |
| `sms` | activity | SMS | `sms_create`, `sms_close` |
| `fbm` | activity | Messenger | `fbm_create`, `fbm_close` |
| `wap` | activity | WhatsApp | `wap_create`, `wap_close` |
| `vbr` | activity | Viber | `vbr_create`, `vbr_close` |
| `igdm` | activity | InstagramDm | `igdm_create`, `igdm_close` |

The parser extracts the prefix before the first `_` to determine the entity type.

---

## Secret Validation

Set the `X-Webhook-Secret` header in your Daktela automation configuration. The handler validates it using constant-time comparison (`hash_equals`).

```yaml
# In sync.yaml
webhook:
  secret: "${WEBHOOK_SECRET}"
```

If the secret is empty in configuration, validation is skipped (useful for development).

## Response Format

```json
{
  "status": "ok",
  "total": 1,
  "created": 0,
  "updated": 1,
  "skipped": 0,
  "failed": 0,
  "duration": 0.123,
  "errors": []
}
```

HTTP status codes:
- `200` — All records synced successfully
- `207` — Partial success (some records failed)
- `401` — Invalid webhook secret
- `500` — Handler error

## Example: Laravel Integration

```php
// routes/api.php
Route::post('/webhook/daktela', [DaktelaWebhookController::class, 'handle']);

// app/Http/Controllers/DaktelaWebhookController.php
class DaktelaWebhookController extends Controller
{
    public function handle(Request $request, WebhookHandler $handler): JsonResponse
    {
        $result = $handler->handle($request);

        return response()->json(
            $result->toResponseArray(),
            $result->httpStatusCode,
        );
    }
}
```

## Example: Plain PHP

```php
// webhook.php
$psr17Factory = new \Nyholm\Psr7\Factory\Psr17Factory();
$creator = new \Nyholm\Psr7Server\ServerRequestCreator($psr17Factory, ...);
$request = $creator->fromGlobals();

$result = $handler->handle($request);

http_response_code($result->httpStatusCode);
header('Content-Type: application/json');
echo json_encode($result->toResponseArray());
```
