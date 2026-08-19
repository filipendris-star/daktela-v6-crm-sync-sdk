<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Integration;

use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\SyncEngine;
use Daktela\CrmSync\Tests\Integration\Fakes\FakeCcAdapter;
use Daktela\CrmSync\Tests\Integration\Fakes\FakeCrmAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Wires SyncEngine + real FieldMapper + real FileSyncStateStore against
 * in-memory fake adapters to exercise the pieces that unit tests cover
 * individually: state persistence, multi-entity ordering, relation maps,
 * and the partial-failure state-saving policy.
 */
final class FullSyncTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/crm-sync-state-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    public function testFullSyncPersistsStateForAccountsAndContacts(): void
    {
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com', 'company_id' => 'acc-1']),
            ],
            accounts: [
                Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
            ],
        );
        $cc = new FakeCcAdapter();
        $engine = $this->engine($crm, $cc);

        $engine->fullSync();

        self::assertFileExists($this->stateFile);
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertIsArray($state);
        self::assertArrayHasKey('account', $state);
        self::assertArrayHasKey('contact', $state);
        // Round-tripped by the real FileSyncStateStore
        self::assertNotNull(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $state['account']));
        self::assertNotNull(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $state['contact']));

        // Both entities reached the CC side through the real FieldMapper
        self::assertCount(1, $cc->accounts);
        self::assertCount(1, $cc->contacts);
        $contact = array_values($cc->contacts)[0];
        // Relation was resolved end-to-end: CRM 'company_id' → CC account ID
        self::assertSame('cc-account-1', $contact->get('account'));
    }

    public function testPartialFailureStillSavesState(): void
    {
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com']),
                Contact::fromArray(['id' => 'crm-c-2', 'full_name' => 'Bob', 'email' => 'bob@acme.com']),
            ],
        );
        $cc = new FakeCcAdapter();
        $cc->failContactOn('bob@acme.com');

        $engine = $this->engine($crm, $cc, accountEnabled: false);

        $result = $engine->syncContactsBatch();

        self::assertSame(1, $result->getFailedCount());
        self::assertSame(1, $result->getCreatedCount());
        // Fix from commit 7ab3eeb parent: state persists despite partial failure,
        // otherwise the failed record would be retried forever on every run.
        self::assertFileExists($this->stateFile);
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertArrayHasKey('contact', $state);
    }

    public function testAllFailedDoesNotSaveState(): void
    {
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com']),
                Contact::fromArray(['id' => 'crm-c-2', 'full_name' => 'Bob', 'email' => 'bob@acme.com']),
            ],
        );
        $cc = new FakeCcAdapter();
        $cc->failContactOn('alice@acme.com');
        $cc->failContactOn('bob@acme.com');

        $engine = $this->engine($crm, $cc, accountEnabled: false);

        $result = $engine->syncContactsBatch();

        self::assertSame(2, $result->getFailedCount());
        self::assertSame(0, $result->getCreatedCount());
        // When every record fails, saving state would silently skip them next run.
        // The state file may exist (written by setUp-adjacent code) but should not
        // contain a 'contact' entry.
        if (file_exists($this->stateFile)) {
            $state = json_decode((string) file_get_contents($this->stateFile), true);
            self::assertArrayNotHasKey('contact', is_array($state) ? $state : []);
        }
    }

    public function testSecondRunReadsStateAndFiltersBySince(): void
    {
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com']),
            ],
        );
        $cc = new FakeCcAdapter();
        $engine = $this->engine($crm, $cc, accountEnabled: false);

        // First run: no prior state → iterator called with since=null
        $engine->syncContactsBatch();

        $firstRunCalls = array_values(array_filter(
            $crm->iterateCalls,
            fn (array $c) => $c['type'] === 'contact',
        ));
        self::assertNotEmpty($firstRunCalls);
        self::assertNull($firstRunCalls[0]['since']);

        // Second run: state file was persisted on disk → iterator receives since!=null
        $engine2 = $this->engine($crm, $cc, accountEnabled: false);
        $engine2->syncContactsBatch();

        $allCalls = array_values(array_filter(
            $crm->iterateCalls,
            fn (array $c) => $c['type'] === 'contact',
        ));
        self::assertGreaterThan(1, count($allCalls));
        $secondCallSince = end($allCalls)['since'];
        self::assertNotNull($secondCallSince);
    }


    public function testOneFailingCustomEntityDoesNotStarveTheOthersOrTheRun(): void
    {
        // A cc_to_crm entry whose adapter lacks write support throws (so its own
        // watermark is not advanced over unexported records). That must stay
        // scoped to its slot: the other entries and the typed steps still run,
        // and the caller still receives FullSyncResult.
        $crm = new FakeCrmAdapter(
            contacts: [Contact::fromArray(['id' => 'c1', 'full_name' => 'John', 'email' => 'j@test.com'])],
            accounts: [Account::fromArray(['id' => 'a1', 'company_name' => 'Acme', 'external_id' => 'acme'])],
        );

        $exportEntry = new \Daktela\CrmSync\Config\CustomEntitySyncConfig(
            name: 'persons_export',
            enabled: true,
            direction: SyncDirection::CcToCrm,
            source: 'contact',
            target: 'persons',
            mappingFile: 'export.yaml',
        );

        $engine = $this->engineWithCustomEntities($crm, new FakeCcAdapter(), [$exportEntry]);

        $results = $engine->fullSync();

        // The run completed and reported the failure per entity.
        self::assertSame(1, $results->contact?->getTotalCount(), 'contacts still synced');
        $exportResult = $results->customEntities['persons_export'] ?? null;
        self::assertNotNull($exportResult);
        self::assertSame(1, $exportResult->getFailedCount(), 'the failing slot is reported as failed');

        // The failing slot's watermark must NOT have been saved.
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertIsArray($state);
        self::assertArrayNotHasKey('custom:persons_export', $state);
        self::assertArrayHasKey('contact', $state, 'a healthy step still saves its watermark');
    }

    /** @param array<int, \Daktela\CrmSync\Config\CustomEntitySyncConfig> $customEntities */
    private function engineWithCustomEntities(FakeCrmAdapter $crm, FakeCcAdapter $cc, array $customEntities): SyncEngine
    {
        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
        ]);
        $accountMapping = new MappingCollection('account', 'name', [
            new FieldMapping('title', 'company_name'),
            new FieldMapping('name', 'external_id'),
        ]);
        $exportMapping = new MappingCollection('contact', 'name', [
            new FieldMapping('title', 'name'),
        ]);

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'account' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'accounts.yaml'),
                'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
            ],
            mappings: [
                'contact' => $contactMapping,
                'account' => $accountMapping,
            ],
            customEntities: $customEntities,
            customEntityMappings: ['persons_export' => $exportMapping],
        );

        return new SyncEngine(
            ccAdapter: $cc,
            crmAdapter: $crm,
            config: $config,
            logger: new NullLogger(),
            stateStore: new FileSyncStateStore($this->stateFile),
        );
    }


    public function testFailedAccountStepGatesContactsInsteadOfWritingRawCrmIds(): void
    {
        // Contacts resolve account references through the relation maps step 1
        // builds. If step 1 blew up and step 3 ran anyway, the resolver would fall
        // back to the raw CRM foreign key, write it into Daktela, report failed=0
        // and advance the contact watermark — a permanent wrong link.
        $crm = new FailingAccountsCrmAdapter(
            contacts: [Contact::fromArray(['id' => 'c1', 'full_name' => 'John', 'email' => 'j@t.com', 'company_id' => 'crm-a9'])],
        );
        $cc = new FakeCcAdapter();

        $results = $this->engine($crm, $cc)->fullSync();

        self::assertTrue($results->hasStepFailures(), 'the run must not look successful');
        self::assertArrayHasKey('account', $results->stepFailures);
        self::assertArrayHasKey('contact', $results->stepFailures, 'the dependent step is reported too');
        self::assertSame([], $cc->contacts, 'no contact may be written without resolved relations');

        // Nothing was saved at all — the file may not even exist.
        $state = is_file($this->stateFile)
            ? (array) json_decode((string) file_get_contents($this->stateFile), true)
            : [];
        self::assertArrayNotHasKey('contact', $state, 'the gated step must not advance its watermark');
        self::assertArrayNotHasKey('account', $state);
    }


    public function testAccountStepWhereEveryRecordFailedAlsoGatesContacts(): void
    {
        // The gate must not key on "the drain threw": a step in which every record
        // failed produced no relation maps either, and it is the same condition
        // saveState() already refuses to advance the watermark for. Otherwise
        // contacts write raw CRM foreign keys and the run reports itself clean.
        $crm = new FakeCrmAdapter(
            contacts: [Contact::fromArray(['id' => 'c1', 'full_name' => 'John', 'email' => 'j@t.com', 'company_id' => 'crm-a9'])],
            accounts: [Account::fromArray(['id' => 'crm-a9', 'company_name' => 'Acme', 'external_id' => 'acme9'])],
        );
        $cc = new FakeCcAdapter();
        $cc->failAccountOn('acme9');

        $results = $this->engine($crm, $cc)->fullSync();

        self::assertTrue($results->hasStepFailures(), 'an all-failed step must not report a clean run');
        self::assertArrayHasKey('account', $results->stepFailures);
        self::assertArrayHasKey('contact', $results->stepFailures, 'the dependent step is gated too');
        self::assertSame([], $cc->contacts, 'no contact may be written with unresolved relations');

        $state = is_file($this->stateFile)
            ? (array) json_decode((string) file_get_contents($this->stateFile), true)
            : [];
        self::assertArrayNotHasKey('contact', $state);
    }

    private function engine(
        FakeCrmAdapter $crm,
        FakeCcAdapter $cc,
        bool $accountEnabled = true,
    ): SyncEngine {
        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
            new FieldMapping(
                ccField: 'account',
                crmField: 'company_id',
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);
        $accountMapping = new MappingCollection('account', 'name', [
            new FieldMapping('title', 'company_name'),
            new FieldMapping('name', 'external_id'),
        ]);

        $entities = [
            'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
        ];
        if ($accountEnabled) {
            $entities['account'] = new EntitySyncConfig(true, SyncDirection::CrmToCc, 'accounts.yaml');
        }

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: $entities,
            mappings: [
                'contact' => $contactMapping,
                'account' => $accountMapping,
            ],
        );

        return new SyncEngine(
            ccAdapter: $cc,
            crmAdapter: $crm,
            config: $config,
            logger: new NullLogger(),
            stateStore: new FileSyncStateStore($this->stateFile),
        );
    }
}

/** CRM whose account listing fails (503, revoked scope, …) while contacts are fine. */
final class FailingAccountsCrmAdapter extends FakeCrmAdapter
{
    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        throw new \RuntimeException('CRM /organizations returned 503');
        yield from []; // @phpstan-ignore-line unreachable, keeps this a Generator
    }
}
