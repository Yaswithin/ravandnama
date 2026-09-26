<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Note;
use App\Repositories\NoteRepository;

final class NoteService
{
    private const TITLE_MAX_LENGTH = 200;
    private const CONTENT_MAX_LENGTH = 50000;

    public function __construct(private readonly NoteRepository $notes)
    {
    }

    /** @param array<string, mixed> $input */
    public function createForUser(int $userId, array $input): Note
    {
        $errors = $this->unknownFields($input);
        $title = $this->validatedTitle($input, $errors, required: true);
        $content = $this->validatedContent($input, $errors, required: true);

        if ($errors !== []) {
            throw new NoteValidationException($errors);
        }

        return $this->notes->create($userId, $title, $content);
    }

    /** @return list<Note> */
    public function listForUser(int $userId): array
    {
        return $this->notes->findAllForUser($userId);
    }

    public function findByIdForUser(int $id, int $userId): ?Note
    {
        return $this->notes->findByIdForUser($id, $userId);
    }

    /** @param array<string, mixed> $input */
    public function updateForUser(int $id, int $userId, array $input): ?Note
    {
        $current = $this->notes->findByIdForUser($id, $userId);

        if ($current === null) {
            return null;
        }

        $errors = $this->unknownFields($input);

        if ($input === []) {
            $errors['body'] = 'At least one note field must be provided.';
        }

        $title = array_key_exists('title', $input)
            ? $this->validatedTitle($input, $errors, required: true)
            : $current->title;
        $content = array_key_exists('content', $input)
            ? $this->validatedContent($input, $errors, required: true)
            : $current->content;

        if ($errors !== []) {
            throw new NoteValidationException($errors);
        }

        return $this->notes->updateForUser($id, $userId, $title, $content);
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        return $this->notes->deleteForUser($id, $userId);
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
    private function validatedContent(array $input, array &$errors, bool $required): ?string
    {
        if (!array_key_exists('content', $input)) {
            if ($required) {
                $errors['content'] = 'Content is required.';
            }

            return null;
        }

        if (!is_string($input['content'])) {
            $errors['content'] = 'Content must be a string.';

            return null;
        }

        if ($this->characterLength($input['content']) > self::CONTENT_MAX_LENGTH) {
            $errors['content'] = 'Content must be 50000 characters or fewer.';
        }

        return $input['content'];
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function unknownFields(array $input): array
    {
        $errors = [];

        foreach (array_keys($input) as $field) {
            if (!in_array($field, ['title', 'content'], true)) {
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
