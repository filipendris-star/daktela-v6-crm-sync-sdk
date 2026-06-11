<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Entity;

/**
 * Type-agnostic data carrier for records that don't map to a first-class
 * entity (custom entity rows, raw CRM responses fed to write-back mappings).
 */
final class GenericEntity implements EntityInterface
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private ?string $id = null,
        private array $data = [],
        private readonly string $type = 'record',
    ) {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function get(string $field): mixed
    {
        if ($field === 'id') {
            return $this->id;
        }

        return $this->data[$field] ?? null;
    }

    public function set(string $field, mixed $value): void
    {
        $this->data[$field] = $value;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $id = isset($data['id']) ? (string) $data['id'] : null;
        unset($data['id']);

        return new static($id, $data);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = $this->data;
        if ($this->id !== null) {
            $out['id'] = $this->id;
        }

        return $out;
    }
}
