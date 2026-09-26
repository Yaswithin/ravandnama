<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\User;
use PDOException;

final class UserRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findByEmail(string $email): ?User
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, name, email FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $record = $statement->fetch();

        return is_array($record) ? User::fromRecord($record) : null;
    }

    /** @return array{id: int|string, name: string, email: string, password_hash: string}|null */
    public function findCredentialsByEmail(string $email): ?array
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, name, email, password_hash FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $record = $statement->fetch();

        return is_array($record) ? $record : null;
    }

    public function findById(int $id): ?User
    {
        $statement = $this->database->connection()->prepare(
            'SELECT id, name, email FROM users WHERE id = :id LIMIT 1'
        );
        $statement->execute(['id' => $id]);
        $record = $statement->fetch();

        return is_array($record) ? User::fromRecord($record) : null;
    }

    public function create(string $name, string $email, string $passwordHash): User
    {
        $statement = $this->database->connection()->prepare(
            'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)'
        );

        try {
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => $passwordHash,
            ]);
        } catch (PDOException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23000'
                && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new DuplicateEmailException(previous: $exception);
            }

            throw $exception;
        }

        $user = $this->findById((int) $this->database->connection()->lastInsertId());

        if ($user === null) {
            throw new \RuntimeException('The created user could not be loaded.');
        }

        return $user;
    }
}
