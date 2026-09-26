<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Models\User;
use App\Repositories\DuplicateEmailException as RepositoryDuplicateEmailException;
use App\Repositories\UserRepository;
use RuntimeException;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Session $session,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function register(array $input): User
    {
        $errors = [];
        $name = null;
        $email = null;
        $password = null;

        if (!array_key_exists('name', $input)) {
            $errors['name'] = 'Name is required.';
        } elseif (!is_string($input['name'])) {
            $errors['name'] = 'Name must be a string.';
        } else {
            $name = trim($input['name']);

            if ($name === '') {
                $errors['name'] = 'Name cannot be empty.';
            } elseif ($this->characterLength($name) > 120) {
                $errors['name'] = 'Name must be 120 characters or fewer.';
            }
        }

        if (!array_key_exists('email', $input)) {
            $errors['email'] = 'Email is required.';
        } elseif (!is_string($input['email'])) {
            $errors['email'] = 'Email must be a string.';
        } else {
            $email = strtolower(trim($input['email']));

            if ($email === '') {
                $errors['email'] = 'Email cannot be empty.';
            } elseif (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors['email'] = 'Email must be a valid email address of 254 bytes or fewer.';
            }
        }

        if (!array_key_exists('password', $input)) {
            $errors['password'] = 'Password is required.';
        } elseif (!is_string($input['password'])) {
            $errors['password'] = 'Password must be a string.';
        } else {
            $password = $input['password'];

            if ($this->characterLength($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new DuplicateEmailException();
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($passwordHash)) {
            throw new RuntimeException('Password hashing failed.');
        }

        try {
            return $this->users->create($name, $email, $passwordHash);
        } catch (RepositoryDuplicateEmailException $exception) {
            throw new DuplicateEmailException($exception);
        }
    }

    public function login(string $email, string $password): User
    {
        $email = strtolower(trim($email));
        $errors = [];

        if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Email must be a valid email address of 254 bytes or fewer.';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $credentials = $this->users->findCredentialsByEmail($email);

        if ($credentials === null || !password_verify($password, $credentials['password_hash'])) {
            throw new InvalidCredentialsException();
        }

        $this->session->regenerate(true);
        $this->session->set('auth.user_id', (int) $credentials['id']);

        return User::fromRecord($credentials);
    }

    private function characterLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }
}
