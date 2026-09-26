<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Project
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string, int|string|null> $record */
    public static function fromRecord(array $record): self
    {
        return new self(
            (int) $record['id'],
            (string) $record['name'],
            is_string($record['description'] ?? null) ? $record['description'] : null,
            (string) $record['created_at'],
            (string) $record['updated_at'],
        );
    }

    /** @return array{id: int, name: string, description: ?string, created_at: string, updated_at: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
