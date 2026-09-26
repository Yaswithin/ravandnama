<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\ProjectValidationException;
use InvalidArgumentException;

final class ProjectController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly ProjectService $projects,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->auth->requireUser(fn (User $user): Response => Response::json([
            'success' => true,
            'data' => [
                'projects' => array_map(
                    static fn (Project $project): array => $project->toArray(),
                    $this->projects->listForUser($user->id),
                ),
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
                $project = $this->projects->createForUser($user->id, $input);
            } catch (ProjectValidationException $exception) {
                return $this->validationResponse($exception);
            }

            return Response::json([
                'success' => true,
                'data' => [
                    'project' => $project->toArray(),
                ],
            ], 201);
        });
    }

    /** @param array<string, string> $parameters */
    public function show(Request $request, array $parameters): Response
    {
        return $this->auth->requireUser(function (User $user) use ($parameters): Response {
            $id = $this->routeId($parameters);
            $project = $id === null ? null : $this->projects->findByIdForUser($id, $user->id);

            return $project === null
                ? $this->notFound()
                : Response::json(['success' => true, 'data' => ['project' => $project->toArray()]]);
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
                $project = $this->projects->updateForUser($id, $user->id, $input);
            } catch (ProjectValidationException $exception) {
                return $this->validationResponse($exception);
            }

            return $project === null
                ? $this->notFound()
                : Response::json(['success' => true, 'data' => ['project' => $project->toArray()]]);
        });
    }

    /** @param array<string, string> $parameters */
    public function destroy(Request $request, array $parameters): Response
    {
        return $this->auth->requireUser(function (User $user) use ($parameters): Response {
            $id = $this->routeId($parameters);

            if ($id === null || !$this->projects->deleteForUser($id, $user->id)) {
                return $this->notFound();
            }

            return Response::json([
                'success' => true,
                'message' => 'Project deleted successfully.',
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

    private function validationResponse(ProjectValidationException $exception): Response
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
