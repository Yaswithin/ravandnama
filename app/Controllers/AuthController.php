<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;
use App\Services\AuthService;
use App\Services\DuplicateEmailException;
use App\Services\InvalidCredentialsException;
use App\Services\ValidationException;
use InvalidArgumentException;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly Auth $auth,
        private readonly Session $session,
    ) {
    }

    public function register(Request $request): Response
    {
        $input = $this->readJson($request);

        if ($input instanceof Response) {
            return $input;
        }

        try {
            $user = $this->authService->register($input);
        } catch (ValidationException $exception) {
            return Response::json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (DuplicateEmailException) {
            return Response::json([
                'success' => false,
                'message' => 'Email address is already registered.',
            ], 409);
        }

        return Response::json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'user' => $user->toArray(),
            ],
        ], 201);
    }

    public function login(Request $request): Response
    {
        $input = $this->readJson($request);

        if ($input instanceof Response) {
            return $input;
        }

        $errors = [];

        foreach (['email', 'password'] as $field) {
            if (!array_key_exists($field, $input)) {
                $errors[$field] = ucfirst($field) . ' is required.';
            } elseif (!is_string($input[$field])) {
                $errors[$field] = ucfirst($field) . ' must be a string.';
            }
        }

        if ($errors !== []) {
            return Response::json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $errors,
            ], 422);
        }

        try {
            $user = $this->authService->login($input['email'], $input['password']);
        } catch (ValidationException $exception) {
            return Response::json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (InvalidCredentialsException) {
            return Response::json([
                'success' => false,
                'message' => 'Invalid email or password.',
            ], 401);
        }

        return Response::json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user->toArray(),
            ],
        ]);
    }

    public function me(): Response
    {
        return $this->auth->requireUser(
            static fn (User $user): Response => Response::json([
                'success' => true,
                'data' => [
                    'user' => $user->toArray(),
                ],
            ]),
        );
    }

    public function logout(): Response
    {
        $this->session->destroy();

        return Response::json([
            'success' => true,
            'message' => 'Logout successful',
        ]);
    }

    /** @return array<string, mixed>|Response */
    private function readJson(Request $request): array|Response
    {
        try {
            return $request->json();
        } catch (InvalidArgumentException) {
            return Response::json([
                'success' => false,
                'message' => 'Request body must be a valid JSON object with a JSON Content-Type.',
            ], 400);
        }
    }
}
