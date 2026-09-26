<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use LogicException;

final class Csrf
{
    private const SESSION_KEY = 'csrf.token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function validateRequest(Request $request): bool
    {
        $providedToken = $request->header('X-CSRF-Token');

        if ($providedToken === null || $providedToken === '') {
            return false;
        }

        $storedToken = $this->session->get(self::SESSION_KEY);

        return is_string($storedToken)
            && preg_match('/\A[a-f0-9]{64}\z/', $storedToken) === 1
            && hash_equals($storedToken, $providedToken);
    }

    /** Wrap a state-changing route handler with CSRF validation. */
    public function protect(callable $handler): Closure
    {
        return function (Request $request) use ($handler): Response {
            if (!$this->validateRequest($request)) {
                return Response::json([
                    'success' => false,
                    'message' => 'CSRF token validation failed.',
                ], 403);
            }

            $response = $handler($request);

            if (!$response instanceof Response) {
                throw new LogicException('CSRF-protected route handlers must return a Response instance.');
            }

            return $response;
        };
    }
}
