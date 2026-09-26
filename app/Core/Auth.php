<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Repositories\UserRepository;
use LogicException;

final class Auth
{
    public function __construct(
        private readonly Session $session,
        private readonly UserRepository $users,
    ) {
    }

    public function userId(): ?int
    {
        $userId = $this->session->get('auth.user_id');

        if (is_int($userId) && $userId > 0) {
            return $userId;
        }

        if (is_string($userId) && ctype_digit($userId) && (int) $userId > 0) {
            return (int) $userId;
        }

        if ($userId !== null) {
            $this->session->remove('auth.user_id');
        }

        return null;
    }

    public function currentUser(): ?User
    {
        $userId = $this->userId();

        if ($userId === null) {
            return null;
        }

        $user = $this->users->findById($userId);

        if ($user === null) {
            $this->session->remove('auth.user_id');
        }

        return $user;
    }

    /** @param callable(User): Response $next */
    public function requireUser(callable $next): Response
    {
        $user = $this->currentUser();

        if ($user === null) {
            return Response::json([
                'success' => false,
                'message' => 'Authentication required.',
            ], 401);
        }

        $response = $next($user);

        if (!$response instanceof Response) {
            throw new LogicException('Authenticated route handlers must return a Response instance.');
        }

        return $response;
    }
}
