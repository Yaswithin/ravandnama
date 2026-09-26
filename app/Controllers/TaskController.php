<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Services\TaskProjectNotFoundException;
use App\Services\TaskValidationException;
use InvalidArgumentException;

final class TaskController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly TaskService $tasks,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->auth->requireUser(fn (User $user): Response => Response::json([
            'success' => true,
            'data' => [
                'tasks' => array_map(static fn (Task $task): array => $task->toArray(), $this->tasks->listForUser($user->id)),
            ],
        ]));
    }

    public function store(Request $request): Response
    {
        return $this->auth->requireUser(function (User $user) use ($request): Response {
            $input = $this->readJson($request);

            if ($input instanceof Response) {
                return $input;
            }

            try {
                $task = $this->tasks->createForUser($user->id, $input);
            } catch (TaskValidationException $exception) {
                return $this->validationResponse($exception);
            } catch (TaskProjectNotFoundException) {
                return $this->projectNotFound();
            }

            return Response::json([
                'success' => true,
                'data' => [
                    'task' => $task->toArray(),
                ],
            ], 201);
        });
    }

    /** @param array<string, string> $parameters */
    public function show(Request $request, array $parameters): Response
    {
        return $this->auth->requireUser(function (User $user) use ($parameters): Response {
            $id = $this->routeId($parameters);
            $task = $id === null ? null : $this->tasks->findByIdForUser($id, $user->id);

            return $task === null
                ? $this->notFound()
                : Response::json(['success' => true, 'data' => ['task' => $task->toArray()]]);
        });
    }

    /** @param array<string, string> $parameters */
    public function update(Request $request, array $parameters): Response
    {
        return $this->auth->requireUser(function (User $user) use ($request, $parameters): Response {
            $id = $this->routeId($parameters);

            if ($id === null) {
                return $this->notFound();
            }

            $input = $this->readJson($request);

            if ($input instanceof Response) {
                return $input;
            }

            try {
                $task = $this->tasks->updateForUser($id, $user->id, $input);
            } catch (TaskValidationException $exception) {
                return $this->validationResponse($exception);
            } catch (TaskProjectNotFoundException) {
                return $this->projectNotFound();
            }

            return $task === null
                ? $this->notFound()
                : Response::json(['success' => true, 'data' => ['task' => $task->toArray()]]);
        });
    }

    /** @param array<string, string> $parameters */
    public function destroy(Request $request, array $parameters): Response
    {
        return $this->auth->requireUser(function (User $user) use ($parameters): Response {
            $id = $this->routeId($parameters);

            if ($id === null || !$this->tasks->deleteForUser($id, $user->id)) {
                return $this->notFound();
            }

            return Response::json([
                'success' => true,
                'message' => 'Task deleted successfully.',
            ]);
        });
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

    private function validationResponse(TaskValidationException $exception): Response
    {
        return Response::json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $exception->errors(),
        ], 422);
    }

    private function notFound(): Response
    {
        return Response::json([
            'success' => false,
            'message' => 'Task not found.',
        ], 404);
    }

    private function projectNotFound(): Response
    {
        return Response::json([
            'success' => false,
            'message' => 'Project not found.',
        ], 404);
    }

    /** @param array<string, string> $parameters */
    private function routeId(array $parameters): ?int
    {
        $id = filter_var($parameters['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($id) ? $id : null;
    }
}
