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
     * An activity type resolved to an empty rule set, so mapping it produced an
     * empty payload. Reached by a mapping file that declares only `types:` (no
     * `default:` block, which the loader tolerates) while `activity_types`
     * includes a type absent from that map: the base rule set is empty and
     * forType() has nothing to merge over it.
     *
     * Writing the empty payload creates a blank record in the CRM, which a set
     * watermark then advances past — permanently, and never revisited. Failing
     * the record instead makes the loss visible.
     *
     * That does NOT by itself mean a later run picks the record up. On the
     * activity path this is raised per record, so a mapping missing one type of
     * several leaves the others succeeding — a PARTIAL failure, which advances the
     * watermark (docs/07), putting the failed records outside the next window.
     * Fixing the mapping is then not enough; those records need a forced run.
     * Only when every record in the step failed is the watermark withheld and an
     * ordinary re-run sufficient.
     */
    public static function emptyRuleSet(string $entityType, ?string $activityType = null): self
    {
        return new self(sprintf(
            'Mapping for %s%s produced an empty payload — no field rules apply to it. '
            . 'Declare rules under "mappings:" or "default:"%s; '
            . 'refusing to create a blank CRM record.',
            $entityType,
            $activityType !== null ? sprintf(' type "%s"', $activityType) : '',
            $activityType !== null ? ', or add a "types:" entry for this type' : '',
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
