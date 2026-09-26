<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Models\Note;
use App\Models\User;
use App\Services\NoteService;
use App\Services\NoteValidationException;
use InvalidArgumentException;

final class NoteController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly NoteService $notes,
    ) {
    }

    public function index(Request $request): Response
    {
        return $this->auth->requireUser(fn (User $user): Response => Response::json([
            'success' => true,
            'data' => [
                'notes' => array_map(static fn (Note $note): array => $note->toArray(), $this->notes->listForUser($user->id)),
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
                $note = $this->notes->createForUser($user->id, $input);
            } catch (NoteValidationException $exception) {
                return $this->validationResponse($exception);
            }

            return Response::json(['success' => true, 'data' => ['note' => $note->toArray()]], 201);
        });
    }

    /** @param array<string, string> $parameters */
    public function show(Request $request, array $parameters): Response
    {
        return $this->auth->requireUser(function (User $user) use ($parameters): Response {
            $id = $this->routeId($parameters);
            $note = $id === null ? null : $this->notes->findByIdForUser($id, $user->id);

            return $note === null
                ? $this->notFound()
                : Response::json(['success' => true, 'data' => ['note' => $note->toArray()]]);
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
                $note = $this->notes->updateForUser($id, $user->id, $input);
            } catch (NoteValidationException $exception) {
                return $this->validationResponse($exception);
            }

            return $note === null
                ? $this->notFound()
                : Response::json(['success' => true, 'data' => ['note' => $note->toArray()]]);
        });
    }

    /** @param array<string, string> $parameters */
    public function destroy(Request $request, array $parameters): Response
    {
        return $this->auth->requireUser(function (User $user) use ($parameters): Response {
            $id = $this->routeId($parameters);

            if ($id === null || !$this->notes->deleteForUser($id, $user->id)) {
                return $this->notFound();
            }

            return Response::json(['success' => true, 'message' => 'Note deleted successfully.']);
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

    private function validationResponse(NoteValidationException $exception): Response
    {
        return Response::json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $exception->errors(),
        ], 422);
    }

    private function notFound(): Response
    {
        return Response::json(['success' => false, 'message' => 'Note not found.'], 404);
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
