<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Project;

final class ProjectRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(int $userId, string $name, ?string $description): Project
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            INSERT INTO projects (user_id, name, description)
            VALUES (:user_id, :name, :description)
            SQL);
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'description' => $description,
        ]);

        $project = $this->findByIdForUser((int) $this->database->connection()->lastInsertId(), $userId);

        if ($project === null) {
            throw new \RuntimeException('The created project could not be loaded.');
        }

        return $project;
    }

    public function findByIdForUser(int $id, int $userId): ?Project
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            SELECT id, name, description, created_at, updated_at
            FROM projects
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
            SQL);
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $record = $statement->fetch();

        return is_array($record) ? Project::fromRecord($record) : null;
    }

    /** @return list<Project> */
    public function findAllForUser(int $userId): array
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            SELECT id, name, description, created_at, updated_at
            FROM projects
            WHERE user_id = :user_id
            ORDER BY created_at DESC, id DESC
            SQL);
        $statement->execute(['user_id' => $userId]);

        return array_map(Project::fromRecord(...), $statement->fetchAll());
    }

    public function updateForUser(int $id, int $userId, string $name, ?string $description): ?Project
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            UPDATE projects
            SET name = :name,
                description = :description,
                updated_at = CURRENT_TIMESTAMP(6)
            WHERE id = :id AND user_id = :user_id
            SQL);
        $statement->execute([
            'name' => $name,
            'description' => $description,
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $this->findByIdForUser($id, $userId);
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM projects WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }
}
