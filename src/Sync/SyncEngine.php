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
use Daktela\CrmSync\Sync\Result\SyncResult;
use Psr\Log\LoggerInterface;

final class SyncEngine
{
    private readonly BatchSync $batchSync;

    private readonly WebhookSync $webhookSync;

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
            }

            // Step 2: Build relation maps from contact mapping configs
            // Only if accounts weren't synced above (syncAccounts builds relation maps directly)
            if (!$this->config->isEntityEnabled('account')) {
                $this->batchSync->buildRelationMaps();
            }

            // Step 3: Sync contacts (uses relation maps to resolve account references)
            if ($this->config->isEntityEnabled('contact')) {
                $this->logger->info('Full sync: starting contacts');
                $this->batchSync->resetOffsets();
                $syncStartTime = new \DateTimeImmutable();
                $contactResult = new SyncResult();
                do {
                    $batch = $this->batchSync->syncContacts();
                    if ($onBatch !== null) {
                        $onBatch('contact', $batch);
                    }
                    $contactResult->mergeCounts($batch);
                } while (!$batch->isExhausted());
                $contactResult->finish();
                $this->saveState('contact', $syncStartTime, $contactResult);
            }

            // Step 4: Sync activities
            if ($this->config->isEntityEnabled('activity')) {
                $this->logger->info('Full sync: starting activities');
                $this->batchSync->resetOffsets();
                $syncStartTime = new \DateTimeImmutable();
                $activityResult = new SyncResult();
                do {
                    $batch = $this->batchSync->syncActivities($activityTypes);
                    if ($onBatch !== null) {
                        $onBatch('activity', $batch);
                    }
                    $activityResult->mergeCounts($batch);
                } while (!$batch->isExhausted());
                $activityResult->finish();
                $this->saveState('activity', $syncStartTime, $activityResult);
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
                do {
                    $batch = $this->batchSync->syncCustomEntity($customEntry, $mapping);
                    if ($onBatch !== null) {
                        $onBatch("custom:{$customEntry->name}", $batch);
                    }
                    $entryResult->mergeCounts($batch);
                } while (!$batch->isExhausted());
                $entryResult->finish();
                $this->saveState("custom:{$customEntry->name}", $syncStartTime, $entryResult);
                $customEntityResults[$customEntry->name] = $entryResult;
            }

            return new FullSyncResult(
                $accountResult,
                $autoContactResult,
                $contactResult,
                $activityResult,
                $customEntityResults,
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
