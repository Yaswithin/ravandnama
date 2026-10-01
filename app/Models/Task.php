<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\UtcInstant;

final readonly class Task
{
    public function __construct(
        public int $id,
        public int $userId,
        public ?int $projectId,
        public string $title,
        public ?string $description,
        public string $status,
        public ?string $dueAt,
        public ?string $dueAtUtc,
        public ?string $completedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string, int|string|null> $record */
    public static function fromRecord(array $record): self
    {
        return new self(
            (int) $record['id'],
            (int) $record['user_id'],
            isset($record['project_id']) ? (int) $record['project_id'] : null,
            (string) $record['title'],
            is_string($record['description'] ?? null) ? $record['description'] : null,
            (string) $record['status'],
            is_string($record['due_at'] ?? null) ? $record['due_at'] : null,
            is_string($record['due_at_utc'] ?? null) ? $record['due_at_utc'] : null,
            is_string($record['completed_at'] ?? null) ? $record['completed_at'] : null,
            (string) $record['created_at'],
            (string) $record['updated_at'],
        );
    }

    /** @return array{id: int, project_id: ?int, title: string, description: ?string, status: string, due_at: ?string, due_at_utc: ?string, completed_at: string|null, created_at: string, updated_at: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'due_at' => $this->dueAt,
            'due_at_utc' => UtcInstant::toRfc3339($this->dueAtUtc),
            'completed_at' => $this->completedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
