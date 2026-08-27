<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

class ConfigurationException extends SyncException
{
    public static function fileNotFound(string $path): self
    {
        return new self(
            sprintf('Configuration file not found: "%s"', $path),
        );
    }

    public static function invalidMappingFile(string $path, string $reason): self
    {
        return new self(
            sprintf('Invalid mapping file "%s": %s', $path, $reason),
        );
    }

    public static function activityExportNeedsAStateStore(): self
    {
        return new self(
            'Activity export uses initial_sync: now (the default) but no SyncStateStore is configured. '
            . '"now" means "do not push existing history", and only a watermark can deliver that — the '
            . 'engine seeds it on the first run and pushes nothing. Pass a SyncStateStoreInterface. To push history on purpose, use fullSync(forceFullSync: true).',
        );
    }

    public static function factoryNeedsAStateStorePath(): self
    {
        return new self(
            'This config enables an activity export with initial_sync: now, which needs a watermark to '
            . 'seed — otherwise the first run would push the full contact-centre history to the CRM. '
            . 'Pass $stateStorePath to SyncEngineFactory::fromYaml(). Point it somewhere that survives a '
            . 'redeploy — an absolute path on persistent storage, e.g. /var/lib/myapp/sync-state.json. A '
            . 'path inside the release directory is wiped on deploy, and losing the watermark makes the '
            . 'next run seed to now and silently skip everything that closed in the gap.',
        );
    }

    /**
     * The mapped CRM payload carries no value at the mapping's lookup_field.
     *
     * The adapter's upsertActivity() is the only duplicate protection
     * for activity export, and it is driven entirely by that field. With no
     * value there is nothing to look up, so the adapter creates — on every run,
     * for every record, silently, because "found nothing then created" and
     * "created" are indistinguishable downstream.
     *
     * A ConfigurationException, and thrown so it ABORTS THE STEP rather than
     * failing one record. The fault is in the mapping, so it applies to every
     * record equally — and a per-record failure in a mixed-type batch is a
     * PARTIAL failure, which advances the watermark past the records it refused
     * and puts them outside every future incremental window. Aborting holds the
     * watermark, so nothing is lost and the next run retries cleanly.
     *
     * Checked at the write rather than at config load because this is the only
     * place the answer is known. A loader can see that SOME rule targets the
     * field; it cannot see that the rule fired for THIS record. Every way of
     * getting it wrong — a lookup_field naming a cc_field, one written only
     * under a `types:` block that did not apply, a dotted path Activity::get()
     * cannot resolve, a static or append rule, or a mapping built in code and
     * never passed through the loader at all — arrives here as the same fact.
     *
     * @param list<string> $payloadKeys keys present in the mapped CRM payload
     */
    public static function activityExportCannotDedupe(string $lookupField, array $payloadKeys): self
    {
        return new self(sprintf(
            'Activity export cannot dedupe: the mapping\'s lookup_field "%s" has no value in the mapped '
            . 'CRM payload (it contains: %s). upsertActivity() would have nothing to look up and would '
            . 'create a new CRM record on every run. Point lookup_field at a crm_field this mapping '
            . 'writes for this activity type, with a per-record value.',
            $lookupField,
            $payloadKeys === [] ? '(nothing)' : implode(', ', $payloadKeys),
        ));
    }

    /**
     * Two contact-centre activities mapped to the same lookup value.
     *
     * The value is what upsertActivity() finds the CRM record by, so a shared
     * value means both activities resolve to ONE record and the second
     * overwrites the first — silent deletion, reported as a successful run.
     * Non-emptiness cannot catch this; a collision inside one batch proves it.
     *
     * Aborts the step for the same reason as activityExportCannotDedupe(): the
     * fault is in the mapping, and failing records one at a time would advance
     * the watermark past them.
     */
    public static function activityExportLookupIsNotUnique(
        string $lookupField,
        string $value,
        string $firstCcId,
        string $secondCcId,
    ): self {
        return new self(sprintf(
            'Activity export cannot dedupe: activities "%s" and "%s" both map to lookup_field "%s" = "%s". '
            . 'upsertActivity() would resolve both to the same CRM record and the second would overwrite '
            . 'the first. lookup_field must carry a per-record value — a static value or a default that '
            . 'fires for every record cannot identify anything.',
            $firstCcId,
            $secondCcId,
            $lookupField,
            $value,
        ));
    }

    /**
     * The mapping produced an empty CRM payload for this activity type.
     *
     * Writing it would create a blank CRM record. Aborts the step rather than
     * failing the record: a type with no applicable rules is wrong for every
     * activity of that type, and a per-record failure in a mixed batch is a
     * partial failure, which advances the watermark past the refused records.
     */
    public static function activityMappingProducesNoPayload(?string $activityType): self
    {
        return new self(sprintf(
            'Activity mapping produced an empty CRM payload for type "%s": no rule applies, so the export '
            . 'would write a blank record. Add a `default:` block, or a `types:` entry for this type.',
            $activityType ?? 'unknown',
        ));
    }
}
