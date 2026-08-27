<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Webhook;

use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Webhook\WebhookPayloadParser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;

final class WebhookPayloadParserTest extends TestCase
{
    private WebhookPayloadParser $parser;

    protected function setUp(): void
    {
        $this->parser = new WebhookPayloadParser();
    }

    public function testParseContactEvent(): void
    {
        $request = $this->createJsonRequest([
            'event' => 'contact_update',
            'name' => 'contact-123',
        ]);

        $payload = $this->parser->parse($request);

        self::assertSame('contact', $payload->entityType);
        self::assertSame('contact-123', $payload->entityId);
        self::assertSame('contact_update', $payload->event);
        self::assertNull($payload->activityType);
    }

    public function testParseCallEvent(): void
    {
        $request = $this->createJsonRequest([
            'event' => 'call_close',
            'name' => 'call-456',
        ]);

        $payload = $this->parser->parse($request);

        self::assertSame('activity', $payload->entityType);
        self::assertSame('call-456', $payload->entityId);
        self::assertSame(ActivityType::Call, $payload->activityType);
    }

    public function testParseEmailEvent(): void
    {
        $request = $this->createJsonRequest([
            'event' => 'email_create',
            'name' => 'email-789',
        ]);

        $payload = $this->parser->parse($request);

        self::assertSame('activity', $payload->entityType);
        self::assertSame(ActivityType::Email, $payload->activityType);
    }

    public function testParseFallbackToIdField(): void
    {
        $request = $this->createJsonRequest([
            'event' => 'contact_create',
            'id' => 'fallback-id',
        ]);

        $payload = $this->parser->parse($request);

        self::assertSame('fallback-id', $payload->entityId);
    }

    public function testParseFormEncodedBody(): void
    {
        $body = http_build_query(['event' => 'account_update', 'name' => 'acc-1']);
        $request = $this->createRequest($body, 'application/x-www-form-urlencoded');

        $payload = $this->parser->parse($request);

        self::assertSame('account', $payload->entityType);
        self::assertSame('acc-1', $payload->entityId);
    }

    public function testParsePreservesRawData(): void
    {
        $data = ['event' => 'call_close', 'name' => 'c-1', 'extra' => 'value'];
        $request = $this->createJsonRequest($data);

        $payload = $this->parser->parse($request);

        self::assertSame('value', $payload->rawData['extra']);
    }

    /**
     * Regression: EVERY configurable activity type must route as an activity.
     *
     * `igdm` was added to the activity-type map but not the entity map, so
     * entityType came back as "igdm" and WebhookHandler::route() dropped it via
     * its default arm — HTTP 200, zero records, no retry, nothing logged. This
     * asserts the property rather than one channel, so the next channel added to
     * the enum cannot repeat it.
     */
    public function testEveryActivityTypeResolvesToTheActivityEntity(): void
    {
        foreach (ActivityType::cases() as $case) {
            foreach (['create', 'close'] as $verb) {
                $payload = $this->parser->parse($this->createJsonRequest([
                    'event' => $case->value . '_' . $verb,
                    'name' => 'activities_1',
                ]));

                self::assertSame(
                    'activity',
                    $payload->entityType,
                    sprintf('event "%s_%s" must route as an activity', $case->value, $verb),
                );
                self::assertSame($case, $payload->activityType);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createJsonRequest(array $data): ServerRequestInterface
    {
        return $this->createRequest(json_encode($data, JSON_THROW_ON_ERROR), 'application/json');
    }

    private function createRequest(string $body, string $contentType): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')
            ->willReturnMap([
                ['Content-Type', $contentType],
                ['X-Webhook-Secret', ''],
            ]);

        return $request;
    }
}
