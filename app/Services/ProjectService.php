<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Repositories\ProjectRepository;

final class ProjectService
{
    private const NAME_MAX_LENGTH = 200;
    private const DESCRIPTION_MAX_LENGTH = 10000;

    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    /** @param array<string, mixed> $input */
    public function createForUser(int $userId, array $input): Project
    {
        $errors = $this->unknownFields($input);
        $name = $this->validatedName($input, $errors, required: true);
        $description = $this->validatedDescription($input, $errors);

        if ($errors !== []) {
            throw new ProjectValidationException($errors);
        }

        return $this->projects->create($userId, $name, $description);
    }

    /** @return list<Project> */
    public function listForUser(int $userId): array
    {
        return $this->projects->findAllForUser($userId);
    }

    public function findByIdForUser(int $id, int $userId): ?Project
    {
        return $this->projects->findByIdForUser($id, $userId);
    }

    /** @param array<string, mixed> $input */
    public function updateForUser(int $id, int $userId, array $input): ?Project
    {
        $current = $this->projects->findByIdForUser($id, $userId);

        if ($current === null) {
            return null;
        }

        $errors = $this->unknownFields($input);

        if ($input === []) {
            $errors['body'] = 'At least one project field must be provided.';
        }

        $name = array_key_exists('name', $input)
            ? $this->validatedName($input, $errors, required: true)
            : $current->name;
        $description = array_key_exists('description', $input)
            ? $this->validatedDescription($input, $errors)
            : $current->description;

        if ($errors !== []) {
            throw new ProjectValidationException($errors);
        }

        return $this->projects->updateForUser($id, $userId, $name, $description);
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        return $this->projects->deleteForUser($id, $userId);
    }

    /** @param array<string, mixed> $input @param array<string, string> $errors */
    private function validatedName(array $input, array &$errors, bool $required): ?string
    {
        if (!array_key_exists('name', $input)) {
            if ($required) {
                $errors['name'] = 'Name is required.';
            }

            return null;
        }

        if (!is_string($input['name'])) {
            $errors['name'] = 'Name must be a string.';

            return null;
        }

        $name = trim($input['name']);
        $length = $this->characterLength($name);

        if ($name === '') {
            $errors['name'] = 'Name cannot be empty.';
        } elseif ($length > self::NAME_MAX_LENGTH) {
            $errors['name'] = 'Name must be 200 characters or fewer.';
        }

        return $name;
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

    /** @param array<string, mixed> $input @return array<string, string> */
    private function unknownFields(array $input): array
    {
        $errors = [];

        foreach (array_keys($input) as $field) {
            if (!in_array($field, ['name', 'description'], true)) {
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
