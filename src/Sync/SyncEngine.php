<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
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
    private function runIsolated(
        string $entityType,
        SyncResult $result,
        \DateTimeImmutable $syncStartTime,
        callable $drain,
        ?SyncResult $derived = null,
    ): void {
        try {
            $drain();
            $result->finish();
            // $derived is populated BY $drain (the same object, mutated), so it
            // carries this run's counts by the time saveState reads it.
            $this->saveState($entityType, $syncStartTime, $result, $derived);

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

        // Entities the LOADER refused to enable start the run already failed. The
        // fault is as real as one thrown mid-drain — the entity is not syncing — and
        // it has to reach hasStepFailures() and the exit code, or a config typo reads
        // as a clean run forever. The steps themselves are skipped: the loader left
        // the entity disabled. See SyncConfiguration::getEntityFaults().
        $this->stepFailures = $this->config->getEntityFaults();
        foreach ($this->stepFailures as $entityType => $reason) {
            $this->logger->error('Sync step {entityType} disabled by config: {reason}', [
                'entityType' => $entityType,
                'reason' => $reason,
            ]);
        }

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
                }, $autoContactResult);
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
                $this->runIsolated('activity', $activityResult, $syncStartTime, function () use (&$activityResult, $activityTypes, $onBatch, $forceFullSync): void {
                    // Inside the isolated step on purpose: a misconfigured
                    // activity export must not abort the account and contact
                    // steps or deny the caller a FullSyncResult. runIsolated
                    // turns this into stepFailures['activity'], which is what a
                    // scheduler already checks.
                    $this->assertActivityExportCanSeed($forceFullSync);
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
                    // Recorded, not just logged: stepFailures is documented as
                    // covering steps "skipped as a whole", and hasStepFailures()
                    // is what schedulers gate on. Skipping quietly meant an
                    // enabled entity that never syncs on any run reported a clean
                    // run, with no caller able to tell. Reachable in production —
                    // the name is the key into the platform's per-entry mapping
                    // files, so a missing slot lands here.
                    $message = 'no mapping loaded';
                    $this->logger->error('Skipping custom entity "{name}": {error}', [
                        'name' => $customEntry->name,
                        'error' => $message,
                    ]);
                    $this->stepFailures["custom:{$customEntry->name}"] = $message;

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

        $this->warnAboutReseedingExports($entityType);
    }

    /**
     * Clearing a watermark makes the next run look like a first run, and a first
     * run of an EXPORT with `initial_sync: now` seeds the watermark to now and
     * pushes nothing. So a reset does not, on its own, re-push history for those
     * entities — the operator has to run with forceFullSync (which ignores both
     * the watermark and the seed rail) or set `initial_sync: everything`.
     *
     * This cannot be decided for the operator: a cleared watermark is
     * indistinguishable from a never-synced one without inventing extra state, so
     * the SDK says what will happen instead of guessing which was meant.
     */
    private function warnAboutReseedingExports(?string $entityType): void
    {
        $affected = [];

        if (($entityType === null || $entityType === 'activity')
            && $this->config->isEntityEnabled('activity')
            && $this->config->getEntityConfig('activity')?->initialSync === 'now'
        ) {
            $affected[] = 'activity';
        }

        if ($affected === []) {
            return;
        }

        $this->logger->warning(
            'State reset, but {entities} export with initial_sync: now — the next ordinary run will re-seed '
            . 'the watermark to now and push nothing. Run with forceFullSync to actually re-push history.',
            ['entities' => implode(', ', $affected)],
        );
    }

    /**
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function syncContactsBatch(?callable $onBatch = null): SyncResult
    {
        // A drain of one entity starts at the beginning of its result set. Any
        // pagination state still held from an earlier, differently-scoped drain
        // (an interrupted fullSync, forced or not) indexes a different query.
        $this->batchSync->resetOffsets();
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
        $this->batchSync->resetOffsets(); // see syncContactsBatch()
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
        $this->saveState('account', $syncStartTime, $accountResult, $autoContactResult);

        return new AccountSyncResult($accountResult, $autoContactResult);
    }

    /**
     * @param ActivityType[] $activityTypes
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function syncActivitiesBatch(array $activityTypes = [], ?callable $onBatch = null): SyncResult
    {
        // Always false here: fullSync() is the only writer of the force flag and
        // clears it in its finally, so a direct batch call is never forced.
        // The exception message points at fullSync(forceFullSync: true).
        $this->assertActivityExportCanSeed(false);
        $this->batchSync->resetOffsets(); // see syncContactsBatch()
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

    /**
     * Refuse an activity export that would back-export history nobody asked for.
     *
     * An EXPLICIT `initial_sync: now` means "do not push existing history".
     * Only a watermark can deliver that: the engine seeds it on the first run
     * and pushes nothing. With no state store there is nothing to seed, so the
     * first run exports the tenant's entire activity history, and so does every
     * run after it.
     *
     * `initial_sync: everything` is not refused: the operator asked for the
     * history, so pushing it is correct. Without a watermark every later run
     * repeats it, which the adapter's upsert makes idempotent on a CRM that can
     * find an activity by its lookup field and does not on one that cannot — a
     * distinction the SDK cannot see, so it warns rather than guesses.
     *
     * An ABSENT `initial_sync` is treated as "everything" and warned about, never
     * refused. The key is new in 1.2.0, so every config written against an earlier
     * release omits it; refusing those would take a working deployment down on a
     * minor upgrade to enforce a preference it never expressed.
     */
    private function assertActivityExportCanSeed(bool $forceFullSync): void
    {
        // A deliberate one-off history push, explicitly requested by the caller.
        if ($forceFullSync || $this->stateStore !== null) {
            return;
        }

        // Deliberately NOT gated on `enabled`: the seeding rail this protects
        // (BatchSync::syncActivities) is not gated on it either, so gating here
        // left a disabled entity's direct syncActivitiesBatch() call unguarded.
        // fullSync() already checks isEntityEnabled before its activity step.
        $activityConfig = $this->config->getEntityConfig('activity');
        if ($activityConfig === null) {
            return;
        }

        // Only an EXPLICIT "now" is refused. See EntitySyncConfig::$initialSync.
        if ($activityConfig->initialSync === 'now') {
            throw ConfigurationException::activityExportNeedsAStateStore();
        }

        if ($activityConfig->initialSync === null) {
            $this->logger->warning(
                'Activity export has no initial_sync setting, so it keeps the pre-1.2.0 behaviour '
                . '("everything"): EVERY run re-reads and re-writes the full contact-centre history. '
                . 'Set initial_sync explicitly — "now" (which needs a state store) becomes the default in 2.0.',
            );

            return;
        }

        $this->logger->warning(
            'Activity export uses initial_sync: everything with no state store — EVERY run re-reads '
            . 'and re-writes the full contact-centre history, not just the first. That is idempotent '
            . 'only if your CRM can find an activity by its lookup field; otherwise add a state store.',
        );
    }

    /**
     * @param ?SyncResult $derived a result produced as a side effect of $result's
     *        records (auto-created contacts), which shares $result's watermark:
     *        once it advances, the accounts these were derived from leave the
     *        window and the missing contacts are never created. An all-failed
     *        derived result is therefore RECORDED in stepFailures — it would
     *        otherwise be invisible to any scheduler checking hasStepFailures() —
     *        but it does NOT withhold the watermark; see the body for why.
     */
    private function saveState(
        string $entityType,
        \DateTimeImmutable $syncStartTime,
        SyncResult $result,
        ?SyncResult $derived = null,
    ): void {
        if ($this->stateStore === null) {
            return;
        }

        if ($this->allRecordsFailed($result)) {
            $this->logger->warning('State not saved for {entityType}: all {failedCount} records failed', [
                'entityType' => $entityType,
                'failedCount' => $result->getFailedCount(),
            ]);

            return;
        }

        // A wholly-failed DERIVED result (auto-created contacts) is reported, but
        // it does NOT withhold the watermark. Withholding looked safer and is
        // worse: an auto-contact mapping that fails deterministically — a missing
        // required field — never starts succeeding, so the account watermark never
        // advances and every run re-scans and re-upserts the entire CRM account
        // set, indefinitely. It is also arbitrary at the boundary: at 99 of 100
        // derived records failing the watermark advances anyway. So this follows
        // the same policy as any other partial failure (docs/07): the window
        // advances, the failure is visible in stepFailures and the exit code, and
        // forceFullSync is the recovery.
        if ($derived !== null && $this->allRecordsFailed($derived)) {
            $message = sprintf('all %d derived records failed', $derived->getFailedCount());
            $this->logger->error('Sync step {entityType}: {error}', [
                'entityType' => $entityType,
                'error' => $message,
            ]);
            $this->stepFailures[$entityType] = $message;
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

    private function allRecordsFailed(SyncResult $result): bool
    {
        return $result->getTotalCount() > 0 && $result->getFailedCount() === $result->getTotalCount();
    }
}
