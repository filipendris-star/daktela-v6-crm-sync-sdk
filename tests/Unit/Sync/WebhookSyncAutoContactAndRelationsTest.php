<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\AutoCreateContactConfig;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SkipIfExistsMode;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\WebhookSync;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The webhook paths that the main WebhookSyncTest does not reach: relation
 * resolution, the auto-contact skip rules, and the early returns.
 *
 * These matter because the webhook path and the batch path write the SAME CRM
 * and CC records. Anywhere they disagree, the data a customer ends up with
 * depends on which path happened to carry the record — which is not a difference
 * anyone can debug from the outside.
 */
final class WebhookSyncAutoContactAndRelationsTest extends TestCase
{
    // ── relation resolution parity with the batch path ──────────────────────

    /**
     * A `relation:` block on a contact mapping must resolve here exactly as it
     * does on the batch path. It did not: this path skipped relation resolution
     * entirely, so a webhook-delivered contact got the RAW CRM foreign key written
     * into its Daktela account field while the batch path wrote the resolved one.
     */
    public function testAContactRelationIsResolvedOnTheWebhookPath(): void
    {
        $crm = $this->createMock(CrmAdapterInterface::class);
        $crm->method('findContact')->willReturn(
            new Contact('crm-c1', ['email' => 'ada@example.com', 'account_id' => 'crm-a1']),
        );
        $crm->method('findAccount')->with('crm-a1')->willReturn(
            new Account('crm-a1', ['name' => 'Acme Ltd']),
        );

        $written = null;
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->method('upsertContact')->willReturnCallback(
            function (string $lookup, Contact $c) use (&$written) {
                $written = $c->getData();

                return new UpsertResult(new Contact('cc-1', $c->getData()));
            },
        );

        $sync = $this->webhookSync($cc, $crm, $this->configWithContactRelation());
        $result = $sync->syncContact('crm-c1');

        self::assertSame(1, $result->getTotalCount());
        self::assertIsArray($written);
        self::assertSame(
            'Acme Ltd',
            $written['account'] ?? null,
            'the relation must resolve to the Daktela identity, not the raw CRM key',
        );
    }

    public function testAnUnresolvableRelationFallsBackToTheRawKey(): void
    {
        // Documented pass-through: the referenced record is genuinely absent from
        // the CRM, so there is nothing to resolve to. find* returning null means
        // absence — a lookup that could NOT run throws instead, and that throw
        // fails the record rather than reaching here.
        $crm = $this->createMock(CrmAdapterInterface::class);
        $crm->method('findContact')->willReturn(
            new Contact('crm-c1', ['email' => 'ada@example.com', 'account_id' => 'crm-missing']),
        );
        $crm->method('findAccount')->willReturn(null);

        $written = null;
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->method('upsertContact')->willReturnCallback(
            function (string $lookup, Contact $c) use (&$written) {
                $written = $c->getData();

                return new UpsertResult(new Contact('cc-1', $c->getData()));
            },
        );

        $this->webhookSync($cc, $crm, $this->configWithContactRelation())->syncContact('crm-c1');

        self::assertSame('crm-missing', $written['account'] ?? null);
    }

    // ── early returns ────────────────────────────────────────────────────────

    public function testAContactWebhookWithNoMappingConfiguredIsANoOp(): void
    {
        $crm = $this->createMock(CrmAdapterInterface::class);
        $crm->expects(self::never())->method('findContact');

        $config = new SyncConfiguration(
            instanceUrl: 'https://t', accessToken: 't', database: 'd', batchSize: 10,
            entities: [], mappings: [],
        );

        $result = $this->webhookSync($this->createMock(ContactCentreAdapterInterface::class), $crm, $config)
            ->syncContact('crm-c1');

        self::assertSame(0, $result->getTotalCount());
    }

    public function testAnAccountWebhookWithNoMappingConfiguredIsANoOp(): void
    {
        $crm = $this->createMock(CrmAdapterInterface::class);
        $crm->expects(self::never())->method('findAccount');

        $config = new SyncConfiguration(
            instanceUrl: 'https://t', accessToken: 't', database: 'd', batchSize: 10,
            entities: [], mappings: [],
        );

        $result = $this->webhookSync($this->createMock(ContactCentreAdapterInterface::class), $crm, $config)
            ->syncAccount('crm-a1');

        self::assertSame(0, $result->getTotalCount());
    }

    public function testAnAccountMissingFromTheCrmIsSkippedNotFailed(): void
    {
        // Absent is an answer, not a fault: the account was deleted between the
        // webhook firing and this lookup.
        $crm = $this->createMock(CrmAdapterInterface::class);
        $crm->method('findAccount')->willReturn(null);

        $result = $this->webhookSync(
            $this->createMock(ContactCentreAdapterInterface::class),
            $crm,
            $this->configWithAutoContact([], SkipIfExistsMode::All, []),
        )->syncAccount('crm-a1');

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(SyncStatus::Skipped, $result->getRecords()[0]->status);
    }

    // ── auto-contact skip rules ──────────────────────────────────────────────

    public function testSkipIfEmptySuppressesTheAutoContactWhenEveryNamedFieldIsBlank(): void
    {
        // Without this an account with no contactable details creates a blank CC
        // contact on every account webhook.
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->expects(self::never())->method('upsertContact');

        $result = $this->runAccountWebhook(
            $cc,
            new Account('crm-a1', ['name' => 'Acme', 'email' => '', 'phone' => '']),
            $this->configWithAutoContact([], SkipIfExistsMode::All, ['email', 'phone']),
        );

        self::assertSame(1, $result->getTotalCount(), 'only the account itself');
    }

    public function testSkipIfEmptyDoesNotSuppressWhenOneFieldHasAValue(): void
    {
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->expects(self::once())->method('upsertContact')
            ->willReturn(new UpsertResult(new Contact('cc-c1', []), created: true));

        $result = $this->runAccountWebhook(
            $cc,
            new Account('crm-a1', ['name' => 'Acme', 'email' => 'sales@acme.com', 'phone' => '']),
            $this->configWithAutoContact([], SkipIfExistsMode::All, ['email', 'phone']),
        );

        self::assertSame(2, $result->getTotalCount(), 'account + auto-contact');
    }

    public function testSkipIfExistsAllAsksForOneContactCarryingEveryField(): void
    {
        // Two lookups happen, and only the second is the skip rule: first a probe
        // on the mapping's own lookup_field (is this exact contact already here?),
        // then — only when that finds nothing — the skip_if_exists test.
        $skipCriteria = null;
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->method('findContactBy')->willReturnCallback(
            function (array $criteria) use (&$skipCriteria): ?Contact {
                if (!isset($criteria['account'])) {
                    return null; // the lookup_field probe: no such contact yet
                }
                $skipCriteria = $criteria;

                return new Contact('cc-existing', []);
            },
        );
        $cc->expects(self::never())->method('upsertContact');

        $this->runAccountWebhook(
            $cc,
            new Account('crm-a1', ['name' => 'Acme', 'email' => 'sales@acme.com', 'phone' => '+420111']),
            $this->configWithAutoContact(['email', 'phone'], SkipIfExistsMode::All, []),
        );

        self::assertSame(
            ['account' => 'cc-a1', 'email' => 'sales@acme.com', 'phone' => '+420111'],
            $skipCriteria,
            'mode "all" asks once, scoped to the account, with every field at once',
        );
    }

    public function testSkipIfExistsAllCannotMatchWhenAFieldIsEmpty(): void
    {
        // A blank field cannot participate in an "all fields match" test, so the
        // rule bails out and the contact is created — rather than being suppressed
        // by a partial match on the fields that happen to be filled.
        $accountScopedLookups = 0;
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->method('findContactBy')->willReturnCallback(
            function (array $criteria) use (&$accountScopedLookups): ?Contact {
                if (isset($criteria['account'])) {
                    $accountScopedLookups++;
                }

                return null;
            },
        );
        $cc->expects(self::once())->method('upsertContact')
            ->willReturn(new UpsertResult(new Contact('cc-c1', []), created: true));

        $this->runAccountWebhook(
            $cc,
            new Account('crm-a1', ['name' => 'Acme', 'email' => 'sales@acme.com', 'phone' => '']),
            $this->configWithAutoContact(['email', 'phone'], SkipIfExistsMode::All, []),
        );

        self::assertSame(0, $accountScopedLookups, 'the rule must not even ask on an incomplete field set');
    }

    public function testSkipIfExistsAnySkipsOnTheFirstFieldThatMatches(): void
    {
        $criteriaSeen = [];
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->method('findContactBy')->willReturnCallback(
            function (array $criteria) use (&$criteriaSeen): ?Contact {
                if (!isset($criteria['account'])) {
                    return null; // the lookup_field probe
                }
                $criteriaSeen[] = $criteria;

                return isset($criteria['phone']) ? new Contact('cc-existing', []) : null;
            },
        );
        $cc->expects(self::never())->method('upsertContact');

        $this->runAccountWebhook(
            $cc,
            new Account('crm-a1', ['name' => 'Acme', 'email' => 'sales@acme.com', 'phone' => '+420111']),
            $this->configWithAutoContact(['email', 'phone'], SkipIfExistsMode::Any, []),
        );

        self::assertCount(2, $criteriaSeen, 'each field is asked separately until one matches');
        self::assertArrayHasKey('email', $criteriaSeen[0]);
        self::assertArrayHasKey('phone', $criteriaSeen[1]);
    }

    public function testSkipIfExistsAnyCreatesTheContactWhenNoFieldMatches(): void
    {
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->method('findContactBy')->willReturn(null);
        $cc->expects(self::once())->method('upsertContact')
            ->willReturn(new UpsertResult(new Contact('cc-c1', []), created: true));

        $result = $this->runAccountWebhook(
            $cc,
            new Account('crm-a1', ['name' => 'Acme', 'email' => 'sales@acme.com', 'phone' => '+420111']),
            $this->configWithAutoContact(['email', 'phone'], SkipIfExistsMode::Any, []),
        );

        self::assertSame(2, $result->getTotalCount());
    }

    public function testAFailingAutoContactIsReportedWithoutFailingTheAccount(): void
    {
        $cc = $this->createMock(ContactCentreAdapterInterface::class);
        $cc->method('upsertContact')->willThrowException(new \RuntimeException('CC rejected the contact'));

        $result = $this->runAccountWebhook(
            $cc,
            new Account('crm-a1', ['name' => 'Acme', 'email' => 'sales@acme.com']),
            $this->configWithAutoContact([], SkipIfExistsMode::All, []),
        );

        $statuses = array_map(static fn ($r) => $r->status, $result->getRecords());
        self::assertContains(SyncStatus::Failed, $statuses, 'the auto-contact failure is reported');
        self::assertContains(SyncStatus::Updated, $statuses, 'the account itself still synced');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function runAccountWebhook(
        ContactCentreAdapterInterface $cc,
        Account $account,
        SyncConfiguration $config,
    ): \Daktela\CrmSync\Sync\Result\SyncResult {
        $crm = $this->createMock(CrmAdapterInterface::class);
        $crm->method('findAccount')->willReturn($account);

        $cc->method('upsertAccount')->willReturn(new UpsertResult(new Account('cc-a1', [])));

        return $this->webhookSync($cc, $crm, $config)->syncAccount('crm-a1');
    }

    private function webhookSync(
        ContactCentreAdapterInterface $cc,
        CrmAdapterInterface $crm,
        SyncConfiguration $config,
    ): WebhookSync {
        return new WebhookSync($cc, $crm, new FieldMapper(TransformerRegistry::withDefaults()), $config, new NullLogger());
    }

    private function configWithContactRelation(): SyncConfiguration
    {
        return new SyncConfiguration(
            instanceUrl: 'https://t', accessToken: 't', database: 'd', batchSize: 10,
            entities: ['contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'c.yaml')],
            mappings: [
                'contact' => new MappingCollection('contact', 'name', [
                    new FieldMapping('name', 'email'),
                    new FieldMapping('account', 'account_id', relation: new RelationConfig('account', 'id', 'name')),
                ]),
                'account' => new MappingCollection('account', 'name', [
                    new FieldMapping('name', 'name'),
                ]),
            ],
        );
    }

    /**
     * @param string[] $skipIfExists
     * @param string[] $skipIfEmpty
     */
    private function configWithAutoContact(
        array $skipIfExists,
        SkipIfExistsMode $mode,
        array $skipIfEmpty,
    ): SyncConfiguration {
        return new SyncConfiguration(
            instanceUrl: 'https://t', accessToken: 't', database: 'd', batchSize: 10,
            entities: [
                'account' => new EntitySyncConfig(
                    true,
                    SyncDirection::CrmToCc,
                    'a.yaml',
                    autoCreateContact: new AutoCreateContactConfig('ac.yaml', $skipIfExists, $mode, $skipIfEmpty),
                ),
            ],
            mappings: ['account' => new MappingCollection('account', 'name', [new FieldMapping('name', 'name')])],
            autoCreateContactMappings: [
                'account' => new MappingCollection('contact', 'email', [
                    new FieldMapping('email', 'email'),
                    new FieldMapping('phone', 'phone'),
                ]),
            ],
        );
    }
}
