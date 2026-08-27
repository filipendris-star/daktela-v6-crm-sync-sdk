<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Webhook;

use Daktela\CrmSync\Entity\ActivityType;
use Psr\Http\Message\ServerRequestInterface;

final class WebhookPayloadParser
{
    private const EVENT_ACTIVITY_TYPE_MAP = [
        'call' => ActivityType::Call,
        'email' => ActivityType::Email,
        'web' => ActivityType::Chat,
        'sms' => ActivityType::Sms,
        'fbm' => ActivityType::Messenger,
        'wap' => ActivityType::WhatsApp,
        'vbr' => ActivityType::Viber,
        'igdm' => ActivityType::InstagramDm,
    ];

    public function parse(ServerRequestInterface $request): WebhookPayload
    {
        $contentType = $request->getHeaderLine('Content-Type');
        $data = $this->parseBody($request, $contentType);

        $event = (string) ($data['event'] ?? '');
        $entityId = (string) ($data['name'] ?? $data['id'] ?? '');

        // Infer entity type from event name (e.g., "call_close" → "call" → activity)
        $eventPrefix = $this->extractEventPrefix($event);
        // One map owns "is this prefix an activity". Everything else keeps its
        // prefix as the entity type — `contact` and `account` route on their own
        // names, anything unknown reaches WebhookHandler's warning arm.
        //
        // There used to be a second map listing the activity prefixes as well,
        // and adding `igdm` to only one of them made Instagram DM webhooks
        // resolve to entityType "igdm", which route() dropped: HTTP 200, zero
        // records, no retry. What is left of that map was an identity lookup
        // behind this same `??`, so it is gone.
        $activityType = self::EVENT_ACTIVITY_TYPE_MAP[$eventPrefix] ?? null;
        $entityType = $activityType !== null ? 'activity' : $eventPrefix;

        return new WebhookPayload(
            entityType: $entityType,
            entityId: $entityId,
            event: $event,
            rawData: $data,
            activityType: $activityType,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request, string $contentType): array
    {
        $body = (string) $request->getBody();

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        }

        // Form-encoded
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $this->parseFormBody($body);
        }

        // Try JSON first, fall back to form-encoded
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $this->parseFormBody($body);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFormBody(string $body): array
    {
        parse_str($body, $result);

        $typed = [];
        foreach ($result as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    private function extractEventPrefix(string $event): string
    {
        // Events like "call_close", "email_create", "contact_update" etc.
        $parts = explode('_', $event);

        return $parts[0];
    }
}
