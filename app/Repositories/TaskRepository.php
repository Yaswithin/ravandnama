<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Task;

final class TaskRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(int $userId, string $title, ?string $description, ?string $dueAt): Task
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            INSERT INTO tasks (user_id, title, description, status, due_at)
            VALUES (:user_id, :title, :description, 'pending', :due_at)
            SQL);
        $statement->execute([
            'user_id' => $userId,
            'title' => $title,
            'description' => $description,
            'due_at' => $dueAt,
        ]);

        $task = $this->findByIdForUser((int) $this->database->connection()->lastInsertId(), $userId);

        if ($task === null) {
            throw new \RuntimeException('The created task could not be loaded.');
        }

        return $task;
    }

    public function findByIdForUser(int $id, int $userId): ?Task
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            SELECT id, user_id, title, description, status, due_at,
                   completed_at, created_at, updated_at
            FROM tasks
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
            SQL);
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $record = $statement->fetch();

        return is_array($record) ? Task::fromRecord($record) : null;
    }

    /** @return list<Task> */
    public function findAllForUser(int $userId): array
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            SELECT id, user_id, title, description, status, due_at,
                   completed_at, created_at, updated_at
            FROM tasks
            WHERE user_id = :user_id
            ORDER BY created_at DESC, id DESC
            SQL);
        $statement->execute(['user_id' => $userId]);

        return array_map(Task::fromRecord(...), $statement->fetchAll());
    }

    public function updateForUser(
        int $id,
        int $userId,
        string $title,
        ?string $description,
        string $status,
        ?string $dueAt,
        ?string $completedAt,
    ): ?Task {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            UPDATE tasks
            SET title = :title,
                description = :description,
                status = :status,
                due_at = :due_at,
                completed_at = :completed_at,
                updated_at = CURRENT_TIMESTAMP(6)
            WHERE id = :id AND user_id = :user_id
            SQL);
        $statement->execute([
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'due_at' => $dueAt,
            'completed_at' => $completedAt,
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $this->findByIdForUser($id, $userId);
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM tasks WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }
}
