<?php

declare(strict_types=1);

namespace App\Models;

final readonly class Note
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $title,
        public string $content,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    /** @param array<string, int|string> $record */
    public static function fromRecord(array $record): self
    {
        return new self(
            (int) $record['id'],
            (int) $record['user_id'],
            (string) $record['title'],
            (string) $record['content'],
            (string) $record['created_at'],
            (string) $record['updated_at'],
        );
    }

    /** @return array{id: int, title: string, content: string, created_at: string, updated_at: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
