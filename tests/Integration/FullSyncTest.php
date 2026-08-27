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
    public function testAWhollyFailedAutoContactBatchIsReportedButStillAdvancesTheWatermark(): void
    {
        // Auto-created contacts are derived from their accounts, so a failure there
        // is invisible to the account result — it used to leave hasStepFailures()
        // empty. It is reported now. What it must NOT do is withhold the account
        // watermark: an auto-contact mapping that fails deterministically (a
        // missing required field) never starts succeeding, so the watermark would
        // never advance and every run would re-scan and re-upsert the whole CRM
        // account set, indefinitely. It is also arbitrary at the boundary — at 99
        // of 100 failing, the watermark advances anyway. So it follows the same
        // policy as any other partial failure: window advances, failure visible,
        // forceFullSync is the recovery.
        $crm = new FakeCrmAdapter(accounts: [
            Account::fromArray(['id' => 'a1', 'company_name' => 'Acme', 'external_id' => 'acme', 'email' => 'a@t.com']),
            Account::fromArray(['id' => 'a2', 'company_name' => 'Globex', 'external_id' => 'globex', 'email' => 'b@t.com']),
        ]);
        $cc = new RejectEveryContactCcAdapter();

        $results = $this->engineWithAutoContact($crm, $cc)->fullSync();

        self::assertSame(2, $results->account?->getCreatedCount(), 'the accounts themselves imported cleanly');
        self::assertSame(2, $results->autoContact?->getFailedCount(), 'every derived contact failed');
        self::assertTrue($results->hasStepFailures(), 'and that is visible, not silent');
        self::assertArrayHasKey('account', $results->stepFailures);

        $state = is_file($this->stateFile)
            ? (array) json_decode((string) file_get_contents($this->stateFile), true)
            : [];
        self::assertArrayHasKey(
            'account',
            $state,
            'the watermark still advances — withholding it would re-import every account on every run, forever',
        );
    }

    private function engineWithAutoContact(FakeCrmAdapter $crm, FakeCcAdapter $cc): SyncEngine
    {
        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'account' => new EntitySyncConfig(
                    enabled: true,
                    direction: SyncDirection::CrmToCc,
                    mappingFile: 'accounts.yaml',
                    autoCreateContact: new \Daktela\CrmSync\Config\AutoCreateContactConfig(
                        mappingFile: 'account-contact.yaml',
                    ),
                ),
            ],
            mappings: [
                'account' => new MappingCollection('account', 'name', [
                    new FieldMapping('title', 'company_name'),
                    new FieldMapping('name', 'external_id'),
                ]),
            ],
            autoCreateContactMappings: [
                'account' => new MappingCollection('contact', 'name', [
                    new FieldMapping('name', 'external_id'),
                    new FieldMapping('title', 'company_name'),
                    new FieldMapping('customFields.email', 'email'),
                ]),
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

    public function testACustomEntityDrainScopesRelationFailuresToTheBatch(): void
    {
        // Same invariant as testATransientRelationFaultDoesNotCondemnTheRecordsBehindIt,
        // on the custom-entity path — the one batch entry point that did not reset
        // the cache. Held across a whole drain, a fault lasting one request failed
        // every row behind it, and because the survivors keep the step out of the
        // "all failed" guard the watermark advanced and the run reported clean.
        $crm = new FakeCrmAdapter(
            accounts: [Account::fromArray(['id' => 'crm-a9', 'company_name' => 'Acme', 'external_id' => 'acme'])],
        );
        $crm->customEntities['persons_export'] = [
            ['id' => 'r1', 'full_name' => 'A', 'email' => 'a@t.com', 'company_id' => 'crm-a9'],
            ['id' => 'r2', 'full_name' => 'B', 'email' => 'b@t.com', 'company_id' => 'crm-a9'],
            ['id' => 'r3', 'full_name' => 'C', 'email' => 'c@t.com', 'company_id' => 'crm-a9'],
            ['id' => 'r4', 'full_name' => 'D', 'email' => 'd@t.com', 'company_id' => 'crm-a9'],
        ];

        $cc = new FailAccountOnceCcAdapter();

        $entry = new \Daktela\CrmSync\Config\CustomEntitySyncConfig(
            name: 'persons_export',
            enabled: true,
            direction: SyncDirection::CrmToCc,
            source: 'persons_export',
            target: 'contact',
            mappingFile: 'x.yaml',
        );

        // Typed entities off, so the account step does not resolve crm-a9 first —
        // the fault must land inside the custom-entity drain under test.
        $results = $this->engineWithCustomEntities(
            $crm,
            $cc,
            [$entry],
            batchSize: 2,
            withRelation: true,
            typedEntities: false,
        )->fullSync();

        $r = $results->customEntities['persons_export'] ?? null;
        self::assertNotNull($r);
        self::assertSame(
            2,
            $cc->accountAttempts,
            'one attempt per batch that needed it — not one per record, and not one for the whole drain',
        );
        self::assertSame(
            2,
            $r->getFailedCount(),
            'only the batch that hit the fault fails; the batches after it retry and succeed',
        );
        self::assertSame(2, $r->getCreatedCount());
    }

    private function engineWithCustomEntities(
        FakeCrmAdapter $crm,
        FakeCcAdapter $cc,
        array $customEntities,
        int $batchSize = 100,
        bool $withRelation = false,
        bool $typedEntities = true,
    ): SyncEngine {
        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
        ]);
        $accountMapping = new MappingCollection('account', 'name', [
            new FieldMapping('title', 'company_name'),
            new FieldMapping('name', 'external_id'),
        ]);
        $exportRules = [new FieldMapping('title', 'name')];
        if ($withRelation) {
            // lookup_field is `name`, so each row needs a distinct CC identity or
            // they all collide and the second row merely updates the first.
            $exportRules[] = new FieldMapping('name', 'id');
            $exportRules[] = new FieldMapping(
                ccField: 'account',
                crmField: 'company_id',
                relation: new RelationConfig('account', 'id', 'name'),
            );
        }
        $exportMapping = new MappingCollection('contact', 'name', $exportRules);

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: $batchSize,
            entities: $typedEntities ? [
                'account' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'accounts.yaml'),
                'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
            ] : [],
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

    public function testCrmOutageFailsTheRecordsThatNeedItInsteadOfWritingRawCrmIds(): void
    {
        // A CRM outage takes the account listing AND the per-id lookup with it, so
        // the contact's account reference cannot be resolved by either route. The
        // resolver's fallback is to pass the raw CRM foreign key through, which
        // would write "crm-a9" into the Daktela account field, report failed=0 and
        // advance the contact watermark — a permanent wrong link no run revisits.
        // The contact must fail as a record instead.
        $crm = new FailingAccountsCrmAdapter(
            contacts: [Contact::fromArray(['id' => 'c1', 'full_name' => 'John', 'email' => 'j@t.com', 'company_id' => 'crm-a9'])],
        );
        $cc = new FakeCcAdapter();

        $results = $this->engine($crm, $cc)->fullSync();

        self::assertTrue($results->hasStepFailures(), 'the run must not look successful');
        self::assertArrayHasKey('account', $results->stepFailures);
        self::assertSame([], $cc->contacts, 'no contact may be written with an unresolved relation');
        self::assertSame(1, $results->contact?->getFailedCount(), 'the contact failed as a record');

        // Every contact failed, so saveState refuses the watermark and the whole
        // window is re-covered once the CRM recovers.
        $state = is_file($this->stateFile)
            ? (array) json_decode((string) file_get_contents($this->stateFile), true)
            : [];
        self::assertArrayNotHasKey('contact', $state, 'a step where everything failed must not advance');
        self::assertArrayNotHasKey('account', $state);
    }

    public function testABrokenRelationIsAttemptedOnceNotOncePerReferencingRecord(): void
    {
        // A DETERMINISTIC failure — the referenced account's own Daktela write is
        // rejected — fails identically for every referrer, so the repeat costs
        // nothing and is skipped. Without that, one bad account behind 10k
        // contacts meant 10k CRM reads, 10k rejected writes and 20k error lines.
        // The contacts still fail; it costs one attempt, not one per referrer.
        //
        // A THROWN lookup is the opposite case and is deliberately NOT cached:
        // see testATransientRelationFaultDoesNotCondemnTheRecordsBehindIt.
        $crm = new CountingAccountLookupCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'c1', 'full_name' => 'A', 'email' => 'a@t.com', 'company_id' => 'crm-a9']),
                Contact::fromArray(['id' => 'c2', 'full_name' => 'B', 'email' => 'b@t.com', 'company_id' => 'crm-a9']),
                Contact::fromArray(['id' => 'c3', 'full_name' => 'C', 'email' => 'c@t.com', 'company_id' => 'crm-a9']),
            ],
        );
        $cc = new FakeCcAdapter();
        $cc->failAccountOn('acme'); // the account's own write is rejected, every time

        $results = $this->engine($crm, $cc)->fullSync();

        self::assertSame(3, $results->contact?->getFailedCount(), 'every referring contact still fails');
        self::assertSame([], $cc->contacts, 'and none is written with an unresolved relation');
        self::assertSame(
            1,
            $crm->accountLookups,
            'the broken account is looked up once per batch, not once per contact',
        );
    }

    public function testATransientRelationFaultDoesNotCondemnTheRecordsBehindIt(): void
    {
        // The failure cache must not outlive a batch. Held for the whole run, a
        // fault lasting ONE request condemned every record behind it — and because
        // the survivors keep the step out of the "all failed" guard, the watermark
        // advanced and hasStepFailures() stayed empty, so a scheduler exited 0.
        $crm = new BlipOnFirstAccountLookupCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'c1', 'full_name' => 'A', 'email' => 'a@t.com', 'company_id' => 'crm-a9']),
                Contact::fromArray(['id' => 'c2', 'full_name' => 'B', 'email' => 'b@t.com', 'company_id' => 'crm-a9']),
                Contact::fromArray(['id' => 'c3', 'full_name' => 'C', 'email' => 'c@t.com', 'company_id' => 'crm-a9']),
            ],
        );
        $cc = new FakeCcAdapter();

        // The SHIPPED default batch size. A batch of one would make any per-batch
        // cache indistinguishable from no cache, so the earlier version of this
        // test could not fail: every record was its own batch. At 100, all three
        // contacts share a batch, which is where a cached transient failure used
        // to condemn the two behind the blip.
        $results = $this->engine($crm, $cc)->fullSync();

        self::assertSame(1, $results->contact?->getFailedCount(), 'only the record that hit the blip fails');
        self::assertCount(2, $cc->contacts, 'the two behind it sync once the CRM recovers');
    }

    public function testUnresolvableRelationFailsOnlyTheContactsThatNeedIt(): void
    {
        // The case step-level gating could not express, in either direction: one
        // account is unwritable, so contacts referencing it must fail — while
        // contacts that don't reference it keep syncing. Gating the whole contact
        // step on "the account step failed" stalled both, and gating on "the drain
        // threw" missed this partial failure entirely and wrote raw ids for it.
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'c1', 'full_name' => 'John', 'email' => 'j@t.com', 'company_id' => 'crm-a9']),
                Contact::fromArray(['id' => 'c2', 'full_name' => 'Jane', 'email' => 'jane@t.com', 'company_id' => 'crm-a8']),
            ],
            accounts: [
                Account::fromArray(['id' => 'crm-a9', 'company_name' => 'Acme', 'external_id' => 'acme9']),
                Account::fromArray(['id' => 'crm-a8', 'company_name' => 'Globex', 'external_id' => 'globex8']),
            ],
        );
        $cc = new FakeCcAdapter();
        $cc->failAccountOn('acme9');

        $results = $this->engine($crm, $cc)->fullSync();

        // A partial failure is reported per record, not as a step failure: the step
        // ran and produced usable state, so nothing downstream should treat it as
        // unusable — the individual records carry the bad news.
        self::assertFalse($results->hasStepFailures());
        self::assertSame(1, $results->account?->getFailedCount(), 'the unwritable account failed');
        self::assertSame(1, $results->contact?->getFailedCount(), 'only the contact needing it failed');

        self::assertCount(1, $cc->contacts, 'the unaffected contact still synced');
        $synced = array_values($cc->contacts)[0];
        self::assertSame('jane@t.com', (string) $synced->get('email'));
        // Resolved to the Daktela-side account identifier the on-the-fly sync
        // created — not the raw CRM id "crm-a8" the fallback would have written.
        self::assertSame('cc-account-1', (string) $synced->get('account'), 'and its relation resolved');
    }

    public function testAccountGenuinelyAbsentFromTheCrmPassesTheValueThrough(): void
    {
        // docs/03 step 5: when the referenced entity does not exist in the CRM at
        // all — nothing failed, there is simply nothing to resolve to — the value
        // passes through unchanged. This is the documented contract and must stay
        // distinct from a failed resolution attempt.
        $crm = new FakeCrmAdapter(
            contacts: [Contact::fromArray(['id' => 'c1', 'full_name' => 'John', 'email' => 'j@t.com', 'company_id' => 'crm-gone'])],
        );
        $cc = new FakeCcAdapter();

        $results = $this->engine($crm, $cc)->fullSync();

        self::assertSame(0, $results->contact?->getFailedCount());
        self::assertCount(1, $cc->contacts);
        self::assertSame(
            'crm-gone',
            (string) array_values($cc->contacts)[0]->get('account'),
            'unresolved value passed through',
        );
    }

    public function testAFailingRelationMapScanDoesNotAbortTheRun(): void
    {
        // With the account entity disabled, step 2 builds the relation maps by
        // scanning the CRM's accounts unfiltered — the likeliest place in the run
        // for a transient CRM fault, and the one step that used to sit outside the
        // isolation. Letting it propagate aborted fullSync() before FullSyncResult
        // existed, so a scheduler checking hasStepFailures() could not tell it from
        // a crash, and contacts/activities/custom entities never ran.
        $crm = new FailingAccountsCrmAdapter(
            contacts: [Contact::fromArray(['id' => 'c1', 'full_name' => 'John', 'email' => 'j@t.com'])],
        );
        $cc = new FakeCcAdapter();

        $results = $this->engine($crm, $cc, accountEnabled: false)->fullSync();

        self::assertTrue($results->hasStepFailures(), 'the fault must be reported, not thrown');
        self::assertArrayHasKey('relation_maps', $results->stepFailures);
        self::assertSame(1, $results->contact?->getTotalCount(), 'contacts still ran');
        self::assertCount(1, $cc->contacts, 'and a contact with no relation still synced');
    }

    private function engine(
        FakeCrmAdapter $crm,
        FakeCcAdapter $cc,
        bool $accountEnabled = true,
        int $batchSize = 100,
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
            batchSize: $batchSize,
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

/**
 * CRM whose account endpoint is down (503, revoked scope, …) while contacts are fine. The outage takes both routes to an
 * account: the listing the account step drains, and the per-id lookup the
 * on-demand resolver falls back to. A fake that broke only the listing would
 * leave findAccount() returning null, which the contract defines as "this
 * account does not exist" — a different case with a different correct outcome.
 */
final class FailingAccountsCrmAdapter extends FakeCrmAdapter
{
    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        throw new \RuntimeException('CRM /organizations returned 503');
        yield from []; // @phpstan-ignore-line unreachable, keeps this a Generator
    }

    public function findAccount(string $id): ?Account
    {
        throw new \RuntimeException('CRM /organizations/' . $id . ' returned 503');
    }
}

/** Answers account lookups, counting them, so a DETERMINISTIC CC rejection is what fails. */
final class CountingAccountLookupCrmAdapter extends FakeCrmAdapter
{
    public int $accountLookups = 0;

    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        yield from []; // nothing pre-built, so each contact resolves its account on demand
    }

    public function findAccount(string $id): ?Account
    {
        $this->accountLookups++;

        return Account::fromArray(['id' => $id, 'company_name' => 'Acme', 'external_id' => 'acme']);
    }
}

/** A CRM whose account lookup throws once and then works — a one-request blip. */
final class BlipOnFirstAccountLookupCrmAdapter extends FakeCrmAdapter
{
    private int $calls = 0;

    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator
    {
        throw new \RuntimeException('CRM /organizations returned 503');
        yield from []; // @phpstan-ignore-line unreachable, keeps this a Generator
    }

    public function findAccount(string $id): ?Account
    {
        if (++$this->calls === 1) {
            throw new \RuntimeException('CRM /organizations/' . $id . ' returned 503 (transient)');
        }

        return Account::fromArray(['id' => $id, 'company_name' => 'Acme', 'external_id' => 'acme']);
    }
}

/** A CC adapter whose FIRST account write is rejected, then works. */
final class FailAccountOnceCcAdapter extends FakeCcAdapter
{
    public int $accountAttempts = 0;

    public function upsertAccount(string $lookupField, Account $account): \Daktela\CrmSync\Adapter\UpsertResult
    {
        $this->accountAttempts++;
        if ($this->accountAttempts === 1) {
            throw new \RuntimeException('CC rejected the account (transient)');
        }

        return parent::upsertAccount($lookupField, $account);
    }
}

/** Accounts import fine; every derived contact write is rejected. */
final class RejectEveryContactCcAdapter extends FakeCcAdapter
{
    public function upsertContact(string $lookupField, Contact $contact): \Daktela\CrmSync\Adapter\UpsertResult
    {
        throw new \RuntimeException('CC rejected the contact: missing required field');
    }
}
