<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

class ConfigurationException extends SyncException
{
    public static function writeBackFilterMismatch(string $entryName): self
    {
        return new self(sprintf(
            'Custom entity "%s": write_back does not remove records from export_filter — the export would re-process the same batch forever. Fix the write_back/export_filter pairing (write_back must rewrite a field the filter checks).',
            $entryName,
        ));
    }

    public static function fileNotFound(string $path): self
    {
        return new self(
            sprintf('Configuration file not found: "%s"', $path),
        );
    }

    public static function invalidMappingFile(string $path, string $reason): self
    {
        return new self(
            sprintf('Invalid mapping file "%s": %s', $path, $reason),
        );
    }
}
