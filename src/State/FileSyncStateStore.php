<?php

declare(strict_types=1);

namespace Daktela\CrmSync\State;

use Daktela\CrmSync\Exception\StateStoreException;

final class FileSyncStateStore implements SyncStateStoreInterface
{
    public function __construct(
        private readonly string $filePath,
    ) {
    }

    public function getLastSyncTime(string $entityType): ?\DateTimeImmutable
    {
        $data = $this->readData();
        if (!isset($data[$entityType])) {
            return null;
        }

        if (!is_string($data[$entityType])) {
            return null;
        }

        $time = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data[$entityType]);

        return $time !== false ? $time : null;
    }

    public function setLastSyncTime(string $entityType, \DateTimeImmutable $time): void
    {
        $data = $this->readData();
        $data[$entityType] = $time->format(\DateTimeInterface::ATOM);
        $this->writeData($data);
    }

    public function getCursor(string $key): ?string
    {
        $data = $this->readData();
        $cursor = $data['__cursors'][$key] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function setCursor(string $key, ?string $cursor): void
    {
        $data = $this->readData();
        if ($cursor === null || $cursor === '') {
            unset($data['__cursors'][$key]);
            if (($data['__cursors'] ?? null) === []) {
                unset($data['__cursors']);
            }
        } else {
            $cursors = is_array($data['__cursors'] ?? null) ? $data['__cursors'] : [];
            $cursors[$key] = $cursor;
            $data['__cursors'] = $cursors;
        }
        $this->writeData($data);
    }

    public function clear(string $entityType): void
    {
        $data = $this->readData();
        unset($data[$entityType]);
        unset($data['__cursors'][$entityType]);
        $this->writeData($data);
    }

    public function clearAll(): void
    {
        $this->writeData([]);
    }

    /**
     * @return array<string, mixed>
     */
    private function readData(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $contents = file_get_contents($this->filePath);
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeData(array $data): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true)) {
            throw StateStoreException::writeFailed($this->filePath);
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->filePath, $json, LOCK_EX) === false) {
            throw StateStoreException::writeFailed($this->filePath);
        }
    }
}
