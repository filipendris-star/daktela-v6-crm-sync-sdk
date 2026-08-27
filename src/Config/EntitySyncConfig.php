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
     * @param string $initialSync First-run behavior when no sync cursor exists yet
     *        (cc_to_crm entities only): "now" (default) seeds the cursor to the current
     *        time so only future records are pushed; "everything" pushes full history.
     */
    public function __construct(
        public bool $enabled,
        public SyncDirection $direction,
        public string $mappingFile,
        public array $activityTypes = [],
        public ?AutoCreateContactConfig $autoCreateContact = null,
        public array $activityTypeMap = [],
        public ?string $linkDeal = null,
        public string $initialSync = 'now',
    ) {
    }
}
