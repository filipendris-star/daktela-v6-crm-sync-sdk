<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

class MappingException extends SyncException
{
    public static function unknownTransformer(string $name): self
    {
        return new self(
            sprintf('Unknown transformer "%s"', $name),
        );
    }

    /**
     * A mapping rule has no field to write to in the direction it is used.
     *
     * On cc_to_crm the target is crm_field, which the loader lets a static-value
     * rule omit (correct for crm_to_cc, where crm_field is the read side). Such a
     * rule cannot be applied on export, and applying it anyway wrote the value
     * under the empty key.
     */
    public static function missingTargetField(string $ccField, string $direction): self
    {
        return new self(sprintf(
            'Mapping rule for cc_field "%s" has no target field for direction "%s". '
            . 'A %s rule must name crm_field — it is the field being written.',
            $ccField,
            $direction,
            $direction,
        ));
    }

    /**
     * A relation could not be resolved because syncing the referenced entity
     * failed — as opposed to it not existing in the CRM, which is a documented
     * pass-through. Fails the referencing record instead of writing the raw CRM
     * foreign key into Daktela as if it were the resolved value.
     */
    public static function relationResolutionFailed(
        string $entityType,
        string $crmId,
        string $reason,
    ): self {
        return new self(sprintf(
            'Cannot resolve %s reference "%s": syncing it failed (%s). Refusing to write the unresolved CRM id.',
            $entityType,
            $crmId,
            $reason,
        ));
    }
}
