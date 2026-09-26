<?php

declare(strict_types=1);

namespace App\Models;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
    ) {
    }

    /**
     * @param array{id: int|string, name: string, email: string} $record
     */
    public static function fromRecord(array $record): self
    {
        return new self(
            (int) $record['id'],
            $record['name'],
            $record['email'],
        );
    }

    /** @return array{id: int, name: string, email: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
