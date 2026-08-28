<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\SupportsDealLinkingInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\SkipIfExistsMode;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Entity\EntityInterface;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Exception\MappingException;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\NestedValue;
use Daktela\CrmSync\Sync\Result\RecordResult;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Psr\Log\LoggerInterface;

final class WebhookSync
{
    public function __construct(
        private readonly ContactCentreAdapterInterface $ccAdapter,
        private readonly CrmAdapterInterface $crmAdapter,
        private readonly FieldMapper $fieldMapper,
        private readonly SyncConfiguration $config,
        private readonly LoggerInterface $logger,
    ) {
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

            $status = $this->statusFor($upsertResult);
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

            // Relations resolved here too. syncContact() has always done this;
            // omitting it meant an account mapping carrying a `relation:` block
            // got the raw CRM foreign key written into Daktela on this path and
            // the resolved value on the batch path.
            $relationMaps = $this->buildRelationMapsForEntity($account, $mapping);
            $mapped = $this->fieldMapper->map($account, $mapping, SyncDirection::CrmToCc, $relationMaps);
            $upsertResult = $this->ccAdapter->upsertAccount($mapping->lookupField, Account::fromArray($mapped));

            $status = $this->statusFor($upsertResult);
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
            $activity = $this->ccAdapter->findActivity($id, $type);
            if ($activity === null) {
                $result->addRecord(new RecordResult('activity', $id, null, SyncStatus::Skipped));
                $result->finish();
                return $result;
            }

            // Per-activity-type rules must apply here exactly as on the batch
            // path: the two paths write the same CRM records, so mapping them
            // differently would leave inconsistent data behind depending on
            // which path happened to carry the activity.
            $typeMapping = $mapping->forType($type->value);

            $mapped = $this->fieldMapper->map($activity, $typeMapping, SyncDirection::CcToCrm);
            if ($mapped === []) {
                throw MappingException::emptyRuleSet('activity', $type->value);
            }

            // The one place this is knowable: does THIS record carry a value at
            // the mapping's lookup_field? Without it upsertActivity() has nothing
            // to look up and creates on every run — silently, since "found
            // nothing then created" and "created" look identical downstream.
            //
            // Reported as a Failed record rather than rethrown: this path handles one
            // event and keeps no watermark, so there is nothing to protect by aborting,
            // and the caller needs the failure in the HTTP response. The batch path
            // rethrows instead, because there a partial failure moves the window.
            //
            // Checked here and not at config load, for the reason given in
            // BatchSync::syncActivityToCrm(): a loader sees which rules exist, not
            // which fired.
            $lookupValue = $mapped[$typeMapping->lookupField] ?? null;
            if ($lookupValue === null || $lookupValue === '' || is_array($lookupValue)) {
                throw ConfigurationException::activityExportCannotDedupe($typeMapping->lookupField, array_keys($mapped));
            }

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
            // first one created. That decision belongs to the adapter's upsert,
            // which is the only thing that can actually look in the CRM.
            $synced = $this->crmAdapter->upsertActivity($typeMapping->lookupField, $mappedActivity);

            // An empty id is the same fact as no id; report it the same way.
            $crmId = $synced->getId();
            $crmId = $crmId === '' ? null : $crmId;

            // Updated, meaning "the CRM now matches". upsert does not report which
            // branch it took, and nothing else here knows either, so this is the
            // one verdict that is never wrong. The batch path reports the same.
            $status = SyncStatus::Updated;
            $result->addRecord(new RecordResult('activity', $id, $crmId, $status));
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
     * Same three-way verdict the batch path reports (BatchSync::syncEntityToCc):
     * hardcoding Updated meant a webhook-driven first export reported created=0.
     */
    private function statusFor(UpsertResult $upsertResult): SyncStatus
    {
        if ($upsertResult->skipped) {
            return SyncStatus::Skipped;
        }

        return $upsertResult->created ? SyncStatus::Created : SyncStatus::Updated;
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

            $identityRule = null;
            foreach ($relatedMapping->mappings as $fm) {
                if ($fm->ccField === $relation->resolveTo) {
                    $identityRule = $fm;
                    break;
                }
            }

            if ($identityRule === null) {
                continue;
            }

            $relatedEntity = $this->findRelatedEntity($relation->entity, $relation->resolveFrom, (string) $crmValue);
            if ($relatedEntity === null) {
                // Genuinely absent in the CRM is the documented pass-through
                // (find* returns null only for absence; a lookup that could not
                // run throws, and that throw fails the record here).
                continue;
            }

            // The mapped value, not the raw CRM field: this map feeds the same
            // FieldMapper lookup the batch path feeds, and that one stores the
            // CC identity produced by the entity's own rule. Reading the field
            // raw disagreed with it under any transformer on that rule.
            $toValue = NestedValue::get(
                $this->fieldMapper->map(
                    $relatedEntity,
                    new MappingCollection($relatedMapping->entityType, $relatedMapping->lookupField, [$identityRule]),
                    SyncDirection::CrmToCc,
                ),
                $relation->resolveTo,
            );
            if ($toValue !== null && (string) $toValue !== '') {
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
