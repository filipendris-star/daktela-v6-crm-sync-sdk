<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Sync\SyncDirection;

/**
 * One entry under sync.custom_entities[].
 *
 * Direction crm_to_cc (import): pulls records from an adapter-defined CRM-side object
 * ({@see $source}) and upserts them into a Daktela platform entity ({@see $target}).
 *
 * Direction cc_to_crm (export): enumerates a Daktela platform entity ({@see $source},
 * 'contact' or 'account') and upserts the records into an adapter-defined CRM-side
 * object ({@see $target}). Requires the CRM adapter to implement
 * SupportsCustomEntityWriteInterface.
 *
 * Both `source` and `target` are free-form strings:
 *  - the CRM-side value is interpreted by the CRM adapter (e.g. SObject name for
 *    Salesforce, resource path for Pipedrive);
 *  - the CC-side value is restricted to whatever ContactCentreAdapterInterface can
 *    enumerate/store — currently 'contact' and 'account'.
 *
 * The {@see $name} is a stable identifier used as the slot key for the per-entry mapping file
 * (mappings_json[$name] on the platform side) and for state-store keys (custom:$name).
 *
 * Export-only options (ignored for crm_to_cc):
 *  - {@see $initialSync}: "now" (default) seeds the cursor on first run instead of
 *    exporting historical records; "everything" exports full history.
 *  - {@see $sinceField}: CC record field the incremental cut-off applies to.
 *  - {@see $exportFilter}: extra API-level filters on the CC enumeration — e.g.
 *    `{field: name, operator: notlike, value: "pipedrive_person_%"}` to skip
 *    CRM-originated and already-exported records.
 *  - {@see $writeBack}: mapping rules applied (CRM→CC) to the created CRM record,
 *    written onto the CC source record after a successful create — e.g. rename the
 *    contact to `<prefix><crm id>` so it joins the import convention and is never
 *    exported again.
 */
final readonly class CustomEntitySyncConfig
{
    /** Convenience constants — common values, NOT a closed set. */
    public const TARGET_CONTACT = 'contact';
    public const TARGET_ACCOUNT = 'account';

    /**
     * @param array<int, array{field: string, operator: string, value: mixed}> $exportFilter
     * @param FieldMapping[] $writeBack
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public SyncDirection $direction,
        public string $source,
        public string $target,
        public string $mappingFile,
        public string $initialSync = 'now',
        public string $sinceField = 'edited',
        public array $exportFilter = [],
        public array $writeBack = [],
    ) {
    }
}
