<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

/**
 * COMPATIBILITY DECLARATION — NOT CONSUMED BY THE ENGINE. The `cc_to_crm`
 * direction of `custom_entities` is not shipped, and the config loader faults any
 * enabled entry that declares it, so nothing here is ever called.
 *
 * It ships only because adapters in daktela-crm-integrations already declare it;
 * removing it would stop them loading. That is the sole reason, and it is not a
 * standing one: this interface must either be consumed by the custom-entity export
 * feature or deleted together with the adapters' `implements` clauses. Do not build
 * anything else against it, and do not treat its presence as evidence the feature
 * exists.
 *
 * Removal target: 2.0.
 *
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
