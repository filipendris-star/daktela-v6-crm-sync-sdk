<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Webhook;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\SyncEngine;
use Daktela\CrmSync\Webhook\WebhookHandler;
use Daktela\CrmSync\Webhook\WebhookPayloadParser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;

final class WebhookHandlerTest extends TestCase
{
    public function testInvalidSecretReturns401(): void
    {
        $handler = $this->createHandler('my-secret');

        $request = $this->createRequest(
            '{"event": "contact_update", "name": "c-1"}',
            'application/json',
            'wrong-secret',
        );

        $result = $handler->handle($request);

        self::assertSame(401, $result->httpStatusCode);
    }

    public function testValidSecretProcessesRequest(): void
    {
        $handler = $this->createHandler('my-secret');

        $request = $this->createRequest(
            '{"event": "contact_update", "name": "crm-1"}',
            'application/json',
            'my-secret',
        );

        $result = $handler->handle($request);

        self::assertSame(200, $result->httpStatusCode);
    }

    public function testEmptySecretSkipsValidation(): void
    {
        $handler = $this->createHandler('');

        $request = $this->createRequest(
            '{"event": "contact_update", "name": "crm-1"}',
            'application/json',
            '',
        );

        $result = $handler->handle($request);

        self::assertSame(200, $result->httpStatusCode);
    }

    public function testToResponseArray(): void
    {
        $handler = $this->createHandler('');

        $request = $this->createRequest(
            '{"event": "contact_update", "name": "crm-1"}',
            'application/json',
            '',
        );

        $result = $handler->handle($request);
        $response = $result->toResponseArray();

        self::assertSame('ok', $response['status']);
        self::assertArrayHasKey('total', $response);
        self::assertArrayHasKey('duration', $response);
    }

    private function createHandler(string $secret): WebhookHandler
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')
            ->willReturn(Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']));

        $ccAdapter->method('upsertContact')
            ->willReturn(new UpsertResult(Contact::fromArray(['id' => 'cc-1'])));

        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
        ]);

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
            ],
            mappings: [
                'contact' => $contactMapping,
            ],
            webhookSecret: $secret,
        );

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $config, new NullLogger());

        return new WebhookHandler(
            $engine,
            new WebhookPayloadParser(),
            $secret,
            $this->logger ?? new NullLogger(),
        );
    }

    private ?\Psr\Log\LoggerInterface $logger = null;

    /**
     * Regression: an event whose prefix routes to nothing must not vanish.
     *
     * `igdm` reached this arm for a whole release — HTTP 200, zero records, no
     * error, no failure counter, no retry, nothing above info level. The reply
     * stays 200 (a Daktela automation can do nothing useful with a failure) but
     * a warning has to name the event, or the next dropped channel is invisible
     * the same way.
     */
    public function testAnUnroutableEventIsAnsweredButLoggedAsAWarning(): void
    {
        $logger = new \Daktela\CrmSync\Tests\Support\CapturingLogger();
        $this->logger = $logger;

        $secret = 'test-secret';
        $response = $this->createHandler($secret)->handle($this->createRequest(
            json_encode(['event' => 'sometypo_close', 'name' => 'activities_1'], JSON_THROW_ON_ERROR),
            'application/json',
            $secret,
        ));

        self::assertSame(200, $response->httpStatusCode, 'the automation still gets a 200');
        self::assertSame(0, $response->syncResult->getTotalCount());

        $warnings = $logger->messagesAt('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('sometypo_close', $warnings[0]);
        self::assertStringContainsString('not routable', $warnings[0]);
    }

    private function createRequest(string $body, string $contentType, string $secret): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')
            ->willReturnMap([
                ['Content-Type', $contentType],
                ['X-Webhook-Secret', $secret],
            ]);

        return $request;
    }
}
