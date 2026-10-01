<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\UtcInstant;
use App\Models\Task;
use App\Repositories\TaskRepository;
use App\Repositories\ProjectRepository;
use DateTimeImmutable;

final class TaskService
{
    private const TITLE_MAX_LENGTH = 200;
    private const DESCRIPTION_MAX_LENGTH = 10000;
    private const DUE_AT_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ProjectRepository $projects,
    )
    {
    }

    /** @param array<string, mixed> $input */
    public function createForUser(int $userId, array $input): Task
    {
        $errors = $this->unknownFields($input, ['title', 'description', 'due_at', 'due_at_utc', 'project_id']);
        $title = $this->validatedTitle($input, $errors, required: true);
        $description = $this->validatedDescription($input, $errors);
        $dueAt = $this->validatedDueAt($input, $errors);
        $dueAtUtc = $this->validatedDueAtUtc($input, $errors);
        $projectId = $this->validatedProjectId($input, $errors);

        if ($errors !== []) {
            throw new TaskValidationException($errors);
        }

        $this->assertProjectOwned($projectId, $userId);

        return $this->tasks->create($userId, $title, $description, $dueAt, $dueAtUtc, $projectId);
    }

    /** @return list<Task> */
    public function listForUser(int $userId): array
    {
        return $this->tasks->findAllForUser($userId);
    }

    public function findByIdForUser(int $id, int $userId): ?Task
    {
        return $this->tasks->findByIdForUser($id, $userId);
    }

    /** @param array<string, mixed> $input */
    public function updateForUser(int $id, int $userId, array $input): ?Task
    {
        $current = $this->tasks->findByIdForUser($id, $userId);

        if ($current === null) {
            return null;
        }

        $errors = $this->unknownFields($input, ['title', 'description', 'due_at', 'due_at_utc', 'status', 'project_id']);

        if ($input === []) {
            $errors['body'] = 'At least one task field must be provided.';
        }

        $title = $this->validatedTitle($input, $errors, required: false) ?? $current->title;
        $description = array_key_exists('description', $input)
            ? $this->validatedDescription($input, $errors)
            : $current->description;
        $dueAt = array_key_exists('due_at', $input)
            ? $this->validatedDueAt($input, $errors)
            : $current->dueAt;
        $dueAtUtc = array_key_exists('due_at_utc', $input)
            ? $this->validatedDueAtUtc($input, $errors)
            : $current->dueAtUtc;
        $projectId = array_key_exists('project_id', $input)
            ? $this->validatedProjectId($input, $errors)
            : $current->projectId;

        $status = $current->status;

        if (array_key_exists('status', $input)) {
            if (!is_string($input['status']) || !in_array($input['status'], ['pending', 'completed'], true)) {
                $errors['status'] = 'Status must be pending or completed.';
            } else {
                $status = $input['status'];
            }
        }

        if ($errors !== []) {
            throw new TaskValidationException($errors);
        }

        $this->assertProjectOwned($projectId, $userId);

        $completedAt = match ($status) {
            'completed' => $current->status === 'completed' && $current->completedAt !== null
                ? $current->completedAt
                : (new DateTimeImmutable())->format('Y-m-d H:i:s.u'),
            default => null,
        };

        return $this->tasks->updateForUser(
            $id,
            $userId,
            $title,
            $description,
            $status,
            $dueAt,
            $dueAtUtc,
            $completedAt,
            $projectId,
        );
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        return $this->tasks->deleteForUser($id, $userId);
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function validatedTitle(array $input, array &$errors, bool $required): ?string
    {
        if (!array_key_exists('title', $input)) {
            if ($required) {
                $errors['title'] = 'Title is required.';
            }

            return null;
        }

        if (!is_string($input['title'])) {
            $errors['title'] = 'Title must be a string.';

            return null;
        }

        $title = trim($input['title']);
        $length = $this->characterLength($title);

        if ($title === '') {
            $errors['title'] = 'Title cannot be empty.';
        } elseif ($length > self::TITLE_MAX_LENGTH) {
            $errors['title'] = 'Title must be 200 characters or fewer.';
        }

        return $title;
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function validatedDescription(array $input, array &$errors): ?string
    {
        if (!array_key_exists('description', $input) || $input['description'] === null) {
            return null;
        }

        if (!is_string($input['description'])) {
            $errors['description'] = 'Description must be a string or null.';

            return null;
        }

        $description = trim($input['description']);

        if ($this->characterLength($description) > self::DESCRIPTION_MAX_LENGTH) {
            $errors['description'] = 'Description must be 10000 characters or fewer.';
        }

        return $description === '' ? null : $description;
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function validatedDueAt(array $input, array &$errors): ?string
    {
        if (!array_key_exists('due_at', $input) || $input['due_at'] === null) {
            return null;
        }

        if (!is_string($input['due_at'])) {
            $errors['due_at'] = 'Due date must be a string in Y-m-d H:i:s format or null.';

            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . self::DUE_AT_FORMAT, $input['due_at']);
        $dateErrors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format(self::DUE_AT_FORMAT) !== $input['due_at']) {
            $errors['due_at'] = 'Due date must be a valid date in Y-m-d H:i:s format.';

            return null;
        }

        return $input['due_at'];
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function validatedDueAtUtc(array $input, array &$errors): ?string
    {
        if (!array_key_exists('due_at_utc', $input) || $input['due_at_utc'] === null) {
            return null;
        }

        if (!is_string($input['due_at_utc'])) {
            $errors['due_at_utc'] = 'Due date must be an RFC3339 string with an explicit timezone or null.';

            return null;
        }

        $normalized = UtcInstant::fromRfc3339($input['due_at_utc']);
        if ($normalized === null) {
            $errors['due_at_utc'] = 'Due date must be a valid RFC3339 instant with an explicit timezone and supported precision.';

            return null;
        }

        return $normalized;
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function validatedProjectId(array $input, array &$errors): ?int
    {
        if (!array_key_exists('project_id', $input) || $input['project_id'] === null) {
            return null;
        }

        if (!is_int($input['project_id']) || $input['project_id'] < 1) {
            $errors['project_id'] = 'Project ID must be a positive integer or null.';

            return null;
        }

        return $input['project_id'];
    }

    private function assertProjectOwned(?int $projectId, int $userId): void
    {
        if ($projectId !== null && $this->projects->findByIdForUser($projectId, $userId) === null) {
            throw new TaskProjectNotFoundException();
        }
    }

    /** @param array<string, mixed> $input @param list<string> $allowed @return array<string, string> */
    private function unknownFields(array $input, array $allowed): array
    {
        $errors = [];

        foreach (array_keys($input) as $field) {
            if (!in_array($field, $allowed, true)) {
                $errors[(string) $field] = 'This field is not allowed.';
            }
        }

        return $errors;
    }

    private function characterLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }
}
