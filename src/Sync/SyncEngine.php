<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\State\SyncLedgerInterface;
use Daktela\CrmSync\State\SyncStateStoreInterface;
use Daktela\CrmSync\Sync\Result\AccountSyncResult;
use Daktela\CrmSync\Sync\Result\FullSyncResult;
use Daktela\CrmSync\Sync\Result\RecordResult;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Psr\Log\LoggerInterface;

final class SyncEngine
{
    private readonly BatchSync $batchSync;

    private readonly WebhookSync $webhookSync;

    /** @var array<string, string> Step-level failures of the current fullSync(), entity type => message */
    private array $stepFailures = [];

    public function __construct(
        private readonly ContactCentreAdapterInterface $ccAdapter,
        private readonly CrmAdapterInterface $crmAdapter,
        private readonly SyncConfiguration $config,
        private readonly LoggerInterface $logger,
        ?TransformerRegistry $transformerRegistry = null,
        private readonly ?SyncStateStoreInterface $stateStore = null,
    ) {
        $registry = $transformerRegistry ?? TransformerRegistry::withDefaults();
        $fieldMapper = new FieldMapper($registry);

        $this->batchSync = new BatchSync(
            $this->ccAdapter,
            $this->crmAdapter,
            $fieldMapper,
            $this->config,
            $this->logger,
            $this->stateStore,
        );

        $this->webhookSync = new WebhookSync(
            $this->ccAdapter,
            $this->crmAdapter,
            $fieldMapper,
            $this->config,
            $this->logger,
        );
    }

    /**
     * Run one entity's drain, contained. A step that throws (adapter fault,
     * misconfiguration) must not abort sibling steps, and its watermark must NOT
     * be saved — nothing edited during the outage may fall out of the incremental
     * window. The failure is recorded both on the step's own result and in
     * $stepFailures, so neither a caller inspecting FullSyncResult nor a cron
     * wrapper checking an exit code can mistake the run for a success.
     *
     * Steps are NOT gated on each other's outcome. A later step that needs a
     * relation the account step would have mapped resolves it per record instead
     * (BatchSync::ensureMappingRelations), and fails only the records it actually
     * cannot resolve. Gating whole steps on "the account step failed" stalled
     * every dependent entity for as long as one account was broken while still
     * missing the case that motivated it — a PARTIAL account failure leaves the
     * step "ok" and the relation map incomplete.
     *
     * @param callable(): void $drain
     */
    private function runIsolated(string $entityType, SyncResult $result, \DateTimeImmutable $syncStartTime, callable $drain): void
    {
        try {
            $drain();
            $result->finish();
            $this->saveState($entityType, $syncStartTime, $result);

            // A step in which every record failed produced nothing usable — the
            // same condition saveState() refuses to advance the watermark for.
            // Reported so the run cannot look clean, but it gates nothing.
            if ($result->getTotalCount() > 0 && $result->getFailedCount() === $result->getTotalCount()) {
                $message = sprintf('all %d records failed', $result->getFailedCount());
                $this->logger->error('Sync step {entityType}: {message}', [
                    'entityType' => $entityType,
                    'message' => $message,
                ]);
                $this->stepFailures[$entityType] = $message;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Sync step {entityType} failed: {error}', [
                'entityType' => $entityType,
                'error' => $e->getMessage(),
            ]);
            $result->addRecord(new RecordResult($entityType, null, null, SyncStatus::Failed, $e->getMessage()));
            $result->finish();
            $this->stepFailures[$entityType] = $e->getMessage();
        }
    }

    /**
     * Inject a host-supplied idempotency ledger (e.g. a DB-backed store) used to
     * dedupe one-way exports the CRM cannot search server-side (activities).
     * When unset, exports fall back to the adapter's own upsert/lookup.
     */
    public function setLedger(?SyncLedgerInterface $ledger): void
    {
        $this->batchSync->setLedger($ledger);
        $this->webhookSync->setLedger($ledger);
    }

    /**
     * Full sync in the correct dependency order:
     * 1. Accounts (CRM → Daktela) — builds relation maps for contact→account references
     * 2. Contacts (CRM → Daktela) — uses relation maps to resolve account references
     * 3. Activities (Daktela → CRM)
     *
     * @param ActivityType[] $activityTypes
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function fullSync(
        array $activityTypes = [],
        bool $forceFullSync = false,
        ?callable $onBatch = null,
    ): FullSyncResult {
        $this->batchSync->setForceFullSync($forceFullSync);
        $this->stepFailures = [];

        try {
            $accountResult = null;
            $autoContactResult = null;
            $contactResult = null;
            $activityResult = null;

            // Step 1: Sync accounts first (builds relation maps)
            if ($this->config->isEntityEnabled('account')) {
                $this->logger->info('Full sync: starting accounts');
                $this->batchSync->resetOffsets();
                $syncStartTime = new \DateTimeImmutable();
                $accountResult = new SyncResult();
                $autoContactResult = new SyncResult();
                $this->runIsolated('account', $accountResult, $syncStartTime, function () use (&$accountResult, &$autoContactResult, $onBatch): void {
                    do {
                        $batch = $this->batchSync->syncAccounts();
                        if ($onBatch !== null) {
                            $onBatch('account', $batch->account);
                            if ($batch->autoContact->getTotalCount() > 0) {
                                $onBatch('auto_contact', $batch->autoContact);
                            }
                        }
                        $accountResult->mergeCounts($batch->account);
                        $autoContactResult->mergeCounts($batch->autoContact);
                    } while (!$batch->account->isExhausted());
                });
                $autoContactResult->finish();
            }

            // Step 2: Build relation maps from contact mapping configs
            // Only if accounts weren't synced above (syncAccounts builds relation maps directly)
            //
            // Contained like every other step. This one scans the CRM's accounts
            // unfiltered (no `since`), so it is among the likeliest places for a
            // transient CRM fault — and letting it propagate would abort the whole
            // run before FullSyncResult exists, so a scheduler checking
            // hasStepFailures() could not tell it from a crash. Reported and
            // survived instead: the later steps still run, and a record whose
            // relation cannot be resolved fails on its own (the on-demand resolver
            // retries the lookup per record) rather than being written with a raw
            // CRM foreign key.
            if (!$this->config->isEntityEnabled('account')) {
                try {
                    $this->batchSync->buildRelationMaps();
                } catch (\Throwable $e) {
                    $this->logger->error('Building relation maps failed: {error}', ['error' => $e->getMessage()]);
                    $this->stepFailures['relation_maps'] = $e->getMessage();
                }
            }

            // Step 3: Sync contacts (uses relation maps to resolve account references)
            if ($this->config->isEntityEnabled('contact')) {
                $this->logger->info('Full sync: starting contacts');
                $this->batchSync->resetOffsets();
                $syncStartTime = new \DateTimeImmutable();
                $contactResult = new SyncResult();
                $this->runIsolated('contact', $contactResult, $syncStartTime, function () use (&$contactResult, $onBatch): void {
                    do {
                        $batch = $this->batchSync->syncContacts();
                        if ($onBatch !== null) {
                            $onBatch('contact', $batch);
                        }
                        $contactResult->mergeCounts($batch);
                    } while (!$batch->isExhausted());
                });
            }

            // Step 4: Sync activities
            if ($this->config->isEntityEnabled('activity')) {
                $this->logger->info('Full sync: starting activities');
                $this->batchSync->resetOffsets();
                $syncStartTime = new \DateTimeImmutable();
                $activityResult = new SyncResult();
                $this->runIsolated('activity', $activityResult, $syncStartTime, function () use (&$activityResult, $activityTypes, $onBatch): void {
                    do {
                        $batch = $this->batchSync->syncActivities($activityTypes);
                        if ($onBatch !== null) {
                            $onBatch('activity', $batch);
                        }
                        $activityResult->mergeCounts($batch);
                    } while (!$batch->isExhausted());
                });
            }

            // Step 5: Sync configured custom entities. Runs after typed slots so relation maps
            // and other state populated by accounts/contacts are available.
            $customEntityResults = [];
            foreach ($this->config->getEnabledCustomEntities() as $customEntry) {
                $mapping = $this->config->getCustomEntityMapping($customEntry->name);
                if ($mapping === null) {
                    $this->logger->warning('Skipping custom entity "{name}": no mapping loaded', [
                        'name' => $customEntry->name,
                    ]);
                    continue;
                }

                $this->logger->info('Full sync: starting custom entity {name} (source: {source}, target: {target})', [
                    'name' => $customEntry->name,
                    'source' => $customEntry->source,
                    'target' => $customEntry->target,
                ]);

                $this->batchSync->resetOffsets();
                $syncStartTime = new \DateTimeImmutable();
                $entryResult = new SyncResult();
                $this->runIsolated("custom:{$customEntry->name}", $entryResult, $syncStartTime, function () use (&$entryResult, $customEntry, $mapping, $onBatch): void {
                    do {
                        $batch = $this->batchSync->syncCustomEntity($customEntry, $mapping);
                        if ($onBatch !== null) {
                            $onBatch("custom:{$customEntry->name}", $batch);
                        }
                        $entryResult->mergeCounts($batch);
                    } while (!$batch->isExhausted());
                });
                $customEntityResults[$customEntry->name] = $entryResult;
            }

            return new FullSyncResult(
                $accountResult,
                $autoContactResult,
                $contactResult,
                $activityResult,
                $customEntityResults,
                $this->stepFailures,
            );
        } finally {
            $this->batchSync->setForceFullSync(false);
        }
    }

    /**
     * @throws \RuntimeException if either connection fails
     */
    public function testConnections(): void
    {
        if (!$this->crmAdapter->ping()) {
            throw new \RuntimeException('Cannot connect to CRM API');
        }
        $this->logger->info('CRM connection OK');

        if (!$this->ccAdapter->ping()) {
            throw new \RuntimeException('Cannot connect to Daktela API');
        }
        $this->logger->info('Daktela connection OK');
    }

    public function resetState(?string $entityType = null): void
    {
        if ($this->stateStore === null) {
            return;
        }

        if ($entityType !== null) {
            $this->stateStore->clear($entityType);
        } else {
            $this->stateStore->clearAll();
        }
    }

    /**
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function syncContactsBatch(?callable $onBatch = null): SyncResult
    {
        $syncStartTime = new \DateTimeImmutable();
        $result = new SyncResult();
        do {
            $batch = $this->batchSync->syncContacts();
            if ($onBatch !== null) {
                $onBatch('contact', $batch);
            }
            $result->mergeCounts($batch);
        } while (!$batch->isExhausted());
        $result->finish();
        $this->saveState('contact', $syncStartTime, $result);

        return $result;
    }

    /**
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function syncAccountsBatch(?callable $onBatch = null): AccountSyncResult
    {
        $syncStartTime = new \DateTimeImmutable();
        $accountResult = new SyncResult();
        $autoContactResult = new SyncResult();
        do {
            $batch = $this->batchSync->syncAccounts();
            if ($onBatch !== null) {
                $onBatch('account', $batch->account);
                if ($batch->autoContact->getTotalCount() > 0) {
                    $onBatch('auto_contact', $batch->autoContact);
                }
            }
            $accountResult->mergeCounts($batch->account);
            $autoContactResult->mergeCounts($batch->autoContact);
        } while (!$batch->account->isExhausted());
        $accountResult->finish();
        $autoContactResult->finish();
        $this->saveState('account', $syncStartTime, $accountResult);

        return new AccountSyncResult($accountResult, $autoContactResult);
    }

    /**
     * @param ActivityType[] $activityTypes
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function syncActivitiesBatch(array $activityTypes = [], ?callable $onBatch = null): SyncResult
    {
        $syncStartTime = new \DateTimeImmutable();
        $result = new SyncResult();
        do {
            $batch = $this->batchSync->syncActivities($activityTypes);
            if ($onBatch !== null) {
                $onBatch('activity', $batch);
            }
            $result->mergeCounts($batch);
        } while (!$batch->isExhausted());
        $result->finish();
        $this->saveState('activity', $syncStartTime, $result);

        return $result;
    }

    public function syncContact(string $id): SyncResult
    {
        return $this->webhookSync->syncContact($id);
    }

    public function syncAccount(string $id): SyncResult
    {
        return $this->webhookSync->syncAccount($id);
    }

    public function syncActivity(string $id, ActivityType $type): SyncResult
    {
        return $this->webhookSync->syncActivity($id, $type);
    }

    private function saveState(string $entityType, \DateTimeImmutable $syncStartTime, SyncResult $result): void
    {
        if ($this->stateStore === null) {
            return;
        }

        if ($result->getTotalCount() > 0 && $result->getFailedCount() === $result->getTotalCount()) {
            $this->logger->warning('State not saved for {entityType}: all {failedCount} records failed', [
                'entityType' => $entityType,
                'failedCount' => $result->getFailedCount(),
            ]);

            return;
        }

        if ($result->getFailedCount() > 0) {
            $this->logger->notice('State saved for {entityType} despite {failedCount} failed records (out of {total})', [
                'entityType' => $entityType,
                'failedCount' => $result->getFailedCount(),
                'total' => $result->getTotalCount(),
            ]);
        }

        $this->stateStore->setLastSyncTime($entityType, $syncStartTime);
    }
}
