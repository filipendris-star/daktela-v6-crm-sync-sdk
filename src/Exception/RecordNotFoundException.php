<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

/**
 * The record an operation targeted no longer exists in the remote system.
 *
 * Adapters throw this — and only this — when the remote system positively
 * states the target is gone (HTTP 404/410, or an error code meaning "no such
 * record"). It is the one failure the sync layer can recover from by
 * re-creating, so it must be distinguishable from every other adapter error:
 * a timeout, a 500 or a rate-limit means "unknown, try again", and re-creating
 * on those produces a duplicate while repointing the ledger away from the
 * record that is still there.
 *
 * Extends AdapterException, so existing handlers keep catching it.
 */
class RecordNotFoundException extends AdapterException
{
    public static function forRecord(string $entityType, string $id, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('%s with ID "%s" does not exist in the remote system', $entityType, $id),
            0,
            $previous,
        );
    }
}
