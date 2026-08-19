<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

class AdapterException extends SyncException
{
    public static function readFailed(string $entityType, string $id, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to read %s with ID "%s"', $entityType, $id),
            0,
            $previous,
        );
    }

    public static function cursorPaginationStalled(string $key, ?string $cursor): self
    {
        return new self(sprintf(
            'Cursor pagination for "%s" did not advance: the adapter returned the same next token it was given ("%s"). It must return a new token, or null when the drain is complete.',
            $key,
            $cursor ?? 'null',
        ));
    }

    public static function queryFailed(string $entityType, string $detail): self
    {
        return new self(sprintf('Query for %s failed: %s', $entityType, $detail));
    }

    public static function createFailed(string $entityType, ?\Throwable $previous = null, string $detail = ''): self
    {
        $message = sprintf('Failed to create %s', $entityType);
        if ($detail !== '') {
            $message .= ': ' . $detail;
        }

        return new self($message, 0, $previous);
    }

    public static function updateFailed(string $entityType, string $id, ?\Throwable $previous = null, string $detail = ''): self
    {
        $message = sprintf('Failed to update %s with ID "%s"', $entityType, $id);
        if ($detail !== '') {
            $message .= ': ' . $detail;
        }

        return new self($message, 0, $previous);
    }

    public static function missingId(string $entityType): self
    {
        return new self(
            sprintf('Missing ID for %s entity', $entityType),
        );
    }
}
