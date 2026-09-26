<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Models\Note;

final class NoteRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function create(int $userId, string $title, string $content): Note
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            INSERT INTO notes (user_id, title, content)
            VALUES (:user_id, :title, :content)
            SQL);
        $statement->execute([
            'user_id' => $userId,
            'title' => $title,
            'content' => $content,
        ]);

        $note = $this->findByIdForUser((int) $this->database->connection()->lastInsertId(), $userId);

        if ($note === null) {
            throw new \RuntimeException('The created note could not be loaded.');
        }

        return $note;
    }

    /** @return list<Note> */
    public function findAllForUser(int $userId): array
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            SELECT id, user_id, title, content, created_at, updated_at
            FROM notes
            WHERE user_id = :user_id
            ORDER BY updated_at DESC, id DESC
            SQL);
        $statement->execute(['user_id' => $userId]);

        return array_map(Note::fromRecord(...), $statement->fetchAll());
    }

    public function findByIdForUser(int $id, int $userId): ?Note
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            SELECT id, user_id, title, content, created_at, updated_at
            FROM notes
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
            SQL);
        $statement->execute(['id' => $id, 'user_id' => $userId]);
        $record = $statement->fetch();

        return is_array($record) ? Note::fromRecord($record) : null;
    }

    public function updateForUser(int $id, int $userId, string $title, string $content): ?Note
    {
        $statement = $this->database->connection()->prepare(<<<'SQL'
            UPDATE notes
            SET title = :title,
                content = :content,
                updated_at = CURRENT_TIMESTAMP(6)
            WHERE id = :id AND user_id = :user_id
            SQL);
        $statement->execute([
            'title' => $title,
            'content' => $content,
            'id' => $id,
            'user_id' => $userId,
        ]);

        return $this->findByIdForUser($id, $userId);
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        $statement = $this->database->connection()->prepare(
            'DELETE FROM notes WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $id, 'user_id' => $userId]);

        return $statement->rowCount() > 0;
    }
}
