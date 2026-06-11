<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

/**
 * Optional capability: write custom entity records into the CRM.
 *
 * Required for cc_to_crm custom entity sync (e.g. exporting CC-born contacts
 * as CRM persons). `$entityName` is the adapter-interpreted CRM resource the
 * config declares as the entry's `target` (a URL path for REST-style APIs).
 *
 * Both methods return the resulting CRM record as a flat array including its
 * `id`, so the engine can run identity write-back against the source record.
 */
interface SupportsCustomEntityWriteInterface
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCustomEntity(string $entityName, array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateCustomEntity(string $entityName, string $id, array $data): array;
}
