<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Webhook;

use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\SyncEngine;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class WebhookHandler
{
    public function __construct(
        private readonly SyncEngine $syncEngine,
        private readonly WebhookPayloadParser $parser,
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request): WebhookResult
    {
        if ($this->webhookSecret !== '' && !$this->validateSecret($request)) {
            $this->logger->warning('Webhook request with invalid secret');

            $result = new SyncResult();
            $result->finish();

            return new WebhookResult($result, 401);
        }

        $payload = $this->parser->parse($request);

        $this->logger->info('Webhook received: {event} for {type} {id}', [
            'event' => $payload->event,
            'type' => $payload->entityType,
            'id' => $payload->entityId,
        ]);

        try {
            $syncResult = $this->route($payload);

            $statusCode = $syncResult->getFailedCount() > 0 ? 207 : 200;

            return new WebhookResult($syncResult, $statusCode);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook handling failed: {error}', [
                'error' => $e->getMessage(),
                'event' => $payload->event,
            ]);

            $result = new SyncResult();
            $result->finish();

            return new WebhookResult($result, 500);
        }
    }

    private function validateSecret(ServerRequestInterface $request): bool
    {
        $provided = $request->getHeaderLine('X-Webhook-Secret');

        return hash_equals($this->webhookSecret, $provided);
    }

    private function route(WebhookPayload $payload): SyncResult
    {
        return match ($payload->entityType) {
            // These two look the id up in the CRM, so entityId must be a CRM id:
            // they serve CRM-side webhooks, not Daktela automations. A Daktela
            // `contact_update` sends the Daktela record name, which the CRM does
            // not know — every such event reports Skipped. See docs/06.
            'contact' => $this->syncEngine->syncContact($payload->entityId),
            'account' => $this->syncEngine->syncAccount($payload->entityId),
            'activity' => $this->syncEngine->syncActivity(
                $payload->entityId,
                $payload->activityType ?? ActivityType::Call,
            ),
            default => $this->unroutableEvent($payload),
        };
    }

    /**
     * An event whose prefix maps to nothing still answers 200 with zero records
     * — the sender is a Daktela automation and there is nothing useful it can do
     * with a failure — but it must not be SILENT. An unrouted prefix meant a
     * whole channel was dropped for a release with no signal anywhere: no error,
     * no failure counter, no retry. A warning is what makes that visible in a
     * day rather than in a customer's missing data.
     */
    private function unroutableEvent(WebhookPayload $payload): SyncResult
    {
        $this->logger->warning(
            'Webhook event "{event}" is not routable (entity type "{entityType}") — nothing was synced. '
            . 'Check the Daktela automation name against the event prefix table in docs/06.',
            ['event' => $payload->event, 'entityType' => $payload->entityType],
        );

        return $this->emptyResult();
    }

    private function emptyResult(): SyncResult
    {
        $result = new SyncResult();
        $result->finish();

        return $result;
    }
}
