<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Sync\SyncDirection;

final readonly class EntitySyncConfig
{
    /**
     * @param ActivityType[] $activityTypes
     * @param array<string, string> $activityTypeMap CC activity type value => adapter-defined CRM type key.
     *        Interpretation is adapter-specific (a field value for Pipedrive/Salesforce,
     *        an object/endpoint selector for HubSpot-style APIs). Adapters fall back to
     *        their built-in defaults for unmapped types.
     * @param string|null $linkDeal Deal-linking strategy (e.g. "latest_open"); only honored
     *        by adapters implementing SupportsDealLinkingInterface.
     * @param string|null $initialSync First-run behavior when no sync cursor exists yet
     *        (cc_to_crm entities only): "now" seeds the cursor to the current time so only
     *        future records are pushed; "everything" pushes full history.
     *
     *        NULL means the key is absent from the config, which is NOT the same as "now".
     *        `initial_sync` did not exist before 1.2.0, so every config written against an
     *        earlier release omits it — and defaulting those to "now" would impose this
     *        release's new state-store requirement on configs that predate the setting,
     *        turning a minor upgrade into an outage. An absent key therefore keeps the
     *        pre-1.2.0 behavior ("everything") and warns. The default flips to "now" in 2.0,
     *        where a break is allowed.
     */
    public function __construct(
        public bool $enabled,
        public SyncDirection $direction,
        public string $mappingFile,
        public array $activityTypes = [],
        public ?AutoCreateContactConfig $autoCreateContact = null,
        public array $activityTypeMap = [],
        public ?string $linkDeal = null,
        public ?string $initialSync = null,
    ) {
    }

    /**
     * The effective first-run behavior: an absent `initial_sync` reads as the pre-1.2.0
     * default. Call this instead of comparing $initialSync directly, EXCEPT where the
     * distinction between "absent" and "explicitly now" is the point — the state-store
     * requirement, which only an explicit "now" may impose.
     */
    public function effectiveInitialSync(): string
    {
        return $this->initialSync ?? 'everything';
    }
}
