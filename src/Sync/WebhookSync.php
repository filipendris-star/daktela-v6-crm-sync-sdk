<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsDealLinkingInterface;
use Daktela\CrmSync\State\SyncLedgerLookupInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\SkipIfExistsMode;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Entity\EntityInterface;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\NestedValue;
use Daktela\CrmSync\Sync\Result\RecordResult;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Psr\Log\LoggerInterface;

final class WebhookSync
{
    private ?\Daktela\CrmSync\State\SyncLedgerInterface $ledger = null;

    public function __construct(
        private readonly ContactCentreAdapterInterface $ccAdapter,
        private readonly CrmAdapterInterface $crmAdapter,
        private readonly FieldMapper $fieldMapper,
        private readonly SyncConfiguration $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Same idempotency ledger the batch path uses (see SyncEngine::setLedger).
     * Without it, a webhook-pushed activity is upserted but never recorded, so a
     * later batch run — which creates without a CRM lookup when a ledger is set —
     * would create a second CRM record for the same activity.
     */
    public function setLedger(?\Daktela\CrmSync\State\SyncLedgerInterface $ledger): void
    {
        $this->ledger = $ledger;
    }

    public function syncContact(string $id): SyncResult
    {
        $result = new SyncResult();
        $mapping = $this->config->getMapping('contact');

        if ($mapping === null) {
            $result->finish();
            return $result;
        }

        try {
            $contact = $this->crmAdapter->findContact($id);
            if ($contact === null) {
                $result->addRecord(new RecordResult('contact', $id, null, SyncStatus::Skipped));
                $result->finish();
                return $result;
            }

            $relationMaps = $this->buildRelationMapsForEntity($contact, $mapping);
            $mapped = $this->fieldMapper->map($contact, $mapping, SyncDirection::CrmToCc, $relationMaps);
            $upsertResult = $this->ccAdapter->upsertContact($mapping->lookupField, Contact::fromArray($mapped));

            $status = $upsertResult->skipped ? SyncStatus::Skipped : SyncStatus::Updated;
            $result->addRecord(new RecordResult('contact', $id, $upsertResult->entity->getId(), $status));
        } catch (\Throwable $e) {
            $this->logger->error('Webhook sync failed for contact {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $result->addRecord(new RecordResult('contact', $id, null, SyncStatus::Failed, $e->getMessage()));
        }

        $result->finish();
        return $result;
    }

    public function syncAccount(string $id): SyncResult
    {
        $result = new SyncResult();
        $mapping = $this->config->getMapping('account');

        if ($mapping === null) {
            $result->finish();
            return $result;
        }

        try {
            $account = $this->crmAdapter->findAccount($id);
            if ($account === null) {
                $result->addRecord(new RecordResult('account', $id, null, SyncStatus::Skipped));
                $result->finish();
                return $result;
            }

            $mapped = $this->fieldMapper->map($account, $mapping, SyncDirection::CrmToCc);
            $upsertResult = $this->ccAdapter->upsertAccount($mapping->lookupField, Account::fromArray($mapped));

            $status = $upsertResult->skipped ? SyncStatus::Skipped : SyncStatus::Updated;
            $result->addRecord(new RecordResult('account', $id, $upsertResult->entity->getId(), $status));

            $accountCcId = $upsertResult->entity->getId();
            $contactMapping = $this->config->getAutoCreateContactMapping('account');
            if ($contactMapping !== null && $accountCcId !== null) {
                $autoRecord = $this->autoCreateContactFromAccount($account, $accountCcId, $contactMapping);
                if ($autoRecord !== null) {
                    $result->addRecord($autoRecord);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Webhook sync failed for account {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $result->addRecord(new RecordResult('account', $id, null, SyncStatus::Failed, $e->getMessage()));
        }

        $result->finish();
        return $result;
    }

    public function syncActivity(string $id, ActivityType $type): SyncResult
    {
        $result = new SyncResult();
        $mapping = $this->config->getMapping('activity');

        if ($mapping === null) {
            $result->finish();
            return $result;
        }

        try {
            $ledger = $this->ledger;
            $alreadySynced = $ledger !== null && $id !== '' && $ledger->hasSynced('activity', $id);
            $knownCrmId = $alreadySynced && $ledger instanceof SyncLedgerLookupInterface
                ? $ledger->findCrmId('activity', $id)
                : null;

            $activity = $this->ccAdapter->findActivity($id, $type);
            if ($activity === null) {
                $result->addRecord(new RecordResult('activity', $id, null, SyncStatus::Skipped));
                $result->finish();
                return $result;
            }

            // Per-activity-type rules must apply here exactly as on the batch
            // path: the two paths write the same CRM records (and cooperate via
            // the ledger, which makes a webhook-written payload permanent — a
            // later batch run skips it), so mapping them differently would leave
            // uncorrectable records behind.
            $typeMapping = $mapping->forType($type->value);

            $mapped = $this->fieldMapper->map($activity, $typeMapping, SyncDirection::CcToCrm);
            $mappedActivity = Activity::fromArray($mapped);

            if ($activity->getActivityType() !== null) {
                $mappedActivity->setActivityType($activity->getActivityType());
            }

            $linkDeal = $this->config->getEntityConfig('activity')?->linkDeal;
            if ($linkDeal !== null && $this->crmAdapter instanceof SupportsDealLinkingInterface) {
                $mappedActivity = $this->crmAdapter->linkActivityToDeal($mappedActivity, $linkDeal);
            }

            // One activity emits several events (call_create → call_answer →
            // call_close); every event after the first must UPDATE the record the
            // first one created. When the ledger can name that record we update it
            // directly — otherwise we hand the decision to the adapter's upsert,
            // exactly as this path always has. Deciding to skip here instead would
            // freeze the record for every host whose ledger predates
            // SyncLedgerLookupInterface, including on CRMs whose upsert finds and
            // updates it perfectly well; and second-guessing the adapter's own
            // lookup (which may key on anything) can only disagree with it.
            //
            // Known limit, unchanged by this SDK: on a CRM that cannot search
            // activities, a plain ledger leaves upsert unable to locate the record,
            // so follow-up events add another one. Implementing
            // SyncLedgerLookupInterface is the fix.
            $synced = $knownCrmId !== null
                ? $this->updateOrRecreate($knownCrmId, $typeMapping->lookupField, $mappedActivity)
                : $this->crmAdapter->upsertActivity($typeMapping->lookupField, $mappedActivity);

            // Record in the ledger so a later batch run (create-without-lookup
            // when a ledger is set) skips this activity instead of duplicating it.
            // Only on first export: re-recording every follow-up event would break
            // ledgers whose store has a unique key on (entity_type, cc_id).
            // First export always records. Afterwards, only re-record when we knew
            // an id and it changed (the record was re-created after being deleted
            // CRM-side) — never blindly, or a ledger whose store has a unique key
            // on (entity_type, cc_id) would throw on the second event.
            $recreated = $knownCrmId !== null && $synced->getId() !== $knownCrmId;
            if ($ledger !== null && $id !== '' && (!$alreadySynced || $recreated)) {
                $ledger->recordSynced('activity', $id, $synced->getId());
            }

            $result->addRecord(new RecordResult('activity', $id, $synced->getId(), SyncStatus::Updated));
        } catch (\Throwable $e) {
            $this->logger->error('Webhook sync failed for activity {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $result->addRecord(new RecordResult('activity', $id, null, SyncStatus::Failed, $e->getMessage()));
        }

        $result->finish();
        return $result;
    }

    /**
     * Update the recorded CRM record, falling back to the adapter's upsert when it
     * no longer exists (deleted CRM-side) so the activity can be re-created
     * instead of failing on every event from now on.
     */
    private function updateOrRecreate(string $crmId, string $lookupField, Activity $mappedActivity): Activity
    {
        try {
            return $this->crmAdapter->updateActivity($crmId, $mappedActivity);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Updating recorded CRM activity {crmId} failed ({error}) — re-creating it',
                ['crmId' => $crmId, 'error' => $e->getMessage()],
            );

            return $this->crmAdapter->upsertActivity($lookupField, $mappedActivity);
        }
    }

    private function autoCreateContactFromAccount(
        EntityInterface $account,
        string $accountCcId,
        MappingCollection $contactMapping,
    ): ?RecordResult {
        try {
            $mapped = $this->fieldMapper->map($account, $contactMapping, SyncDirection::CrmToCc);
            $mapped['account'] = $accountCcId;

            $entityConfig = $this->config->getEntityConfig('account');
            $autoContactConfig = $entityConfig?->autoCreateContact;
            $skipIfEmpty = $autoContactConfig->skipIfEmpty ?? [];

            if ($skipIfEmpty !== [] && $this->allFieldsEmpty($mapped, $skipIfEmpty)) {
                $this->logger->debug('Skip auto-contact: required fields are empty', [
                    'account' => $accountCcId,
                ]);
                return null;
            }

            $contact = Contact::fromArray($mapped);
            $lookupValue = $contact->get($contactMapping->lookupField);

            if ($lookupValue !== null) {
                $existing = $this->ccAdapter->findContactBy(
                    [$contactMapping->lookupField => (string) $lookupValue],
                );

                if ($existing === null) {
                    $skipFields = $autoContactConfig->skipIfExistsFields ?? [];
                    $skipMode = $autoContactConfig->skipIfExistsMode ?? SkipIfExistsMode::All;

                    if ($this->shouldSkipAutoContact($mapped, $accountCcId, $skipFields, $skipMode)) {
                        $this->logger->debug('Skip auto-contact: existing contact covers info', [
                            'account' => $accountCcId,
                        ]);
                        return null;
                    }
                }
            }

            $upsertResult = $this->ccAdapter->upsertContact($contactMapping->lookupField, $contact);

            $status = match (true) {
                $upsertResult->skipped => SyncStatus::Skipped,
                $upsertResult->created => SyncStatus::Created,
                default => SyncStatus::Updated,
            };

            return new RecordResult(
                entityType: 'contact',
                sourceId: $account->getId(),
                targetId: $upsertResult->entity->getId(),
                status: $status,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to auto-create contact from account {id}: {error}', [
                'id' => $account->getId(),
                'error' => $e->getMessage(),
            ]);

            return new RecordResult(
                entityType: 'contact',
                sourceId: $account->getId(),
                targetId: null,
                status: SyncStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @param array<string, mixed> $mapped     Mapped contact data
     * @param string[]             $skipFields CC field names to check
     */
    private function shouldSkipAutoContact(
        array $mapped,
        string $accountCcId,
        array $skipFields,
        SkipIfExistsMode $mode,
    ): bool {
        if ($skipFields === []) {
            return false;
        }

        if ($mode === SkipIfExistsMode::All) {
            $criteria = ['account' => $accountCcId];
            foreach ($skipFields as $field) {
                $value = NestedValue::get($mapped, $field);
                if ($value === null || $value === '' || $value === []) {
                    return false; // can't match all if a field is empty
                }
                $criteria[$field] = is_array($value) ? (string) reset($value) : (string) $value;
            }

            return $this->ccAdapter->findContactBy($criteria) !== null;
        }

        // Mode: any — skip if any single field matches
        foreach ($skipFields as $field) {
            $value = NestedValue::get($mapped, $field);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $existing = $this->ccAdapter->findContactBy([
                'account' => $accountCcId,
                $field => is_array($value) ? (string) reset($value) : (string) $value,
            ]);

            if ($existing !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $mapped
     * @param string[]             $fields
     */
    private function allFieldsEmpty(array $mapped, array $fields): bool
    {
        foreach ($fields as $field) {
            $value = NestedValue::get($mapped, $field);
            if ($value !== null && $value !== '' && $value !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds a targeted relation map for a single entity by looking up only
     * the specific related entities it references (e.g., its account).
     *
     * @return array<string, array<string, string>>
     */
    private function buildRelationMapsForEntity(EntityInterface $entity, MappingCollection $entityMapping): array
    {
        $relationMaps = [];

        foreach ($entityMapping->mappings as $mapping) {
            if ($mapping->relation === null) {
                continue;
            }

            $relation = $mapping->relation;

            $crmValue = $entity->get($mapping->crmField);
            if ($crmValue === null || (string) $crmValue === '') {
                continue;
            }

            $relatedMapping = $this->config->getMapping($relation->entity);
            if ($relatedMapping === null) {
                continue;
            }

            $resolveToSourceField = null;
            foreach ($relatedMapping->mappings as $fm) {
                if ($fm->ccField === $relation->resolveTo) {
                    $resolveToSourceField = $fm->crmField;
                    break;
                }
            }

            if ($resolveToSourceField === null) {
                continue;
            }

            $relatedEntity = $this->findRelatedEntity($relation->entity, $relation->resolveFrom, (string) $crmValue);
            if ($relatedEntity === null) {
                continue;
            }

            $toValue = $relatedEntity->get($resolveToSourceField);
            if ($toValue !== null) {
                $relationMaps[$relation->entity] ??= [];
                $relationMaps[$relation->entity][(string) $crmValue] = (string) $toValue;
            }
        }

        return $relationMaps;
    }

    private function findRelatedEntity(string $entityType, string $field, string $value): ?EntityInterface
    {
        return match ($entityType) {
            'account' => $field === 'id'
                ? $this->crmAdapter->findAccount($value)
                : $this->crmAdapter->findAccountByLookup($field, $value),
            'contact' => $field === 'id'
                ? $this->crmAdapter->findContact($value)
                : $this->crmAdapter->findContactByLookup($field, $value),
            default => null,
        };
    }
}
