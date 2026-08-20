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
