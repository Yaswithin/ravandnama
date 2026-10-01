<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ProjectController;
use App\Controllers\TaskController;
use App\Controllers\NoteController;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Repositories\UserRepository;
use App\Repositories\TaskRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\NoteRepository;
use App\Services\AuthService;
use App\Services\ProjectService;
use App\Services\TaskService;
use App\Services\NoteService;

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
    $projectRepository = new ProjectRepository($database);
    $taskController = new TaskController($auth, new TaskService(new TaskRepository($database), $projectRepository));
    $projectController = new ProjectController($auth, new ProjectService($projectRepository));
    $noteController = new NoteController($auth, new NoteService(new NoteRepository($database)));

    $router->get('/api/auth/csrf', static fn (Request $request): Response => Response::json([
        'success' => true,
        'data' => [
            'csrf_token' => $csrf->token(),
        ],
    ]));

    $router->post('/api/auth/register', $csrf->protect([$authController, 'register']));
    $router->post('/api/auth/login', $csrf->protect([$authController, 'login']));
    $router->get('/api/auth/me', [$authController, 'me']);
    $router->put('/api/auth/me', $csrf->protect([$authController, 'updateMe']));
    $router->get('/api/auth/timezones', [$authController, 'timezones']);
    $router->post('/api/auth/logout', $csrf->protect([$authController, 'logout']));

    $router->get('/api/tasks', [$taskController, 'index']);
    $router->post('/api/tasks', $csrf->protect([$taskController, 'store']));
    $router->get('/api/tasks/{id}', [$taskController, 'show']);
    $router->put('/api/tasks/{id}', $csrf->protect([$taskController, 'update']));
    $router->delete('/api/tasks/{id}', $csrf->protect([$taskController, 'destroy']));

    $router->get('/api/projects', [$projectController, 'index']);
    $router->post('/api/projects', $csrf->protect([$projectController, 'store']));
    $router->get('/api/projects/{id}', [$projectController, 'show']);
    $router->put('/api/projects/{id}', $csrf->protect([$projectController, 'update']));
    $router->delete('/api/projects/{id}', $csrf->protect([$projectController, 'destroy']));

    $router->get('/api/notes', [$noteController, 'index']);
    $router->post('/api/notes', $csrf->protect([$noteController, 'store']));
    $router->get('/api/notes/{id}', [$noteController, 'show']);
    $router->put('/api/notes/{id}', $csrf->protect([$noteController, 'update']));
    $router->delete('/api/notes/{id}', $csrf->protect([$noteController, 'destroy']));
};
