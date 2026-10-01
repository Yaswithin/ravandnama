<?php

declare(strict_types=1);

namespace App\Models;

final readonly class User
{
    public const DEFAULT_TIMEZONE = 'Asia/Tehran';

    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $timezone = self::DEFAULT_TIMEZONE,
    ) {
    }

    /**
     * @param array{id: int|string, name: string, email: string, timezone?: string} $record
     */
    public static function fromRecord(array $record): self
    {
        return new self(
            (int) $record['id'],
            $record['name'],
            $record['email'],
            $record['timezone'] ?? self::DEFAULT_TIMEZONE,
        );
    }

    /** @return array{id: int, name: string, email: string, timezone: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
        ];
    }
}
