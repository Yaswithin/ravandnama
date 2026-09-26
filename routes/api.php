<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Services\AuthService;

return static function (Router $router, Database $database): void {
    $router->get('/api/health', static fn (Request $request): Response => Response::json([
        'success' => true,
        'message' => 'Ravandnama API is running',
    ]));

    $router->get('/api/health/db', static function (Request $request) use ($database): Response {
        try {
            $database->connection()->query('SELECT 1');

            return Response::json([
                'success' => true,
                'message' => 'Database connection is healthy',
            ]);
        } catch (\Throwable $exception) {
            error_log('Database health check failed (' . $exception::class . ').');

            return Response::json([
                'success' => false,
                'message' => 'Database connection is unavailable',
            ], 503);
        }
    });

    $session = new Session();
    $csrf = new Csrf($session);
    $users = new UserRepository($database);
    $authService = new AuthService($users, $session);
    $auth = new Auth($session, $users);
    $authController = new AuthController($authService, $auth, $session);

    $router->get('/api/auth/csrf', static fn (Request $request): Response => Response::json([
        'success' => true,
        'data' => [
            'csrf_token' => $csrf->token(),
        ],
    ]));

    $router->post('/api/auth/register', $csrf->protect([$authController, 'register']));
    $router->post('/api/auth/login', $csrf->protect([$authController, 'login']));
    $router->get('/api/auth/me', [$authController, 'me']);
    $router->post('/api/auth/logout', $csrf->protect([$authController, 'logout']));
};
