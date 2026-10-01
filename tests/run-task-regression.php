<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Repositories\ProjectRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Environment::load(dirname(__DIR__) . '/.env');
$database = new Database(require dirname(__DIR__) . '/config/database.php');
$pdo = $database->connection();
$session = new Session();
$session->start();
$users = new UserRepository($database);
$projects = new ProjectRepository($database);
$router = new Router();
$registerRoutes = require dirname(__DIR__) . '/routes/api.php';
$registerRoutes($router, $database);

$userA = null;
$userB = null;
$userAEmail = 'codex-task-a-' . bin2hex(random_bytes(8)) . '@example.invalid';
$userBEmail = 'codex-task-b-' . bin2hex(random_bytes(8)) . '@example.invalid';
$assertions = 0;
$failures = [];

$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;

    if (!$condition) {
        $failures[] = $label;
    }
};

$call = static function (Router $router, string $method, string $path, array $headers = [], string $body = ''): array {
    $response = $router->dispatch(new Request($method, $path, headers: $headers, body: $body));
    ob_start();
    $response->send();
    $responseBody = (string) ob_get_clean();

    return [http_response_code(), $responseBody, json_decode($responseBody, true), $response->header('Cache-Control')];
};

$body = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$headers = static fn (?string $token = null): array => array_filter([
    'Content-Type' => 'application/json',
    'X-CSRF-Token' => $token,
]);
$taskAId = null;
$taskBId = null;
$projectA = null;
$projectB = null;
$orphanTaskId = null;

try {
    $userA = $users->create('Task Regression A', $userAEmail, password_hash('test-only-password-a', PASSWORD_DEFAULT));
    $userB = $users->create('Task Regression B', $userBEmail, password_hash('test-only-password-b', PASSWORD_DEFAULT));
    $projectA = $projects->create($userA->id, 'Task Regression Project A', null);
    $projectB = $projects->create($userB->id, 'Task Regression Project B', null);

    [$csrfStatus, , $csrfResponse] = $call($router, 'GET', '/api/auth/csrf');
    $token = $csrfResponse['data']['csrf_token'] ?? '';
    $assert($csrfStatus === 200 && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'CSRF token endpoint responds successfully');

    [$status] = $call($router, 'POST', '/api/tasks', $headers($token), $body(['title' => 'No user']));
    $assert($status === 401, 'Create without login returns 401 when CSRF is valid');
    [$status] = $call($router, 'GET', '/api/tasks');
    $assert($status === 401, 'List without login returns 401');
    [$status] = $call($router, 'POST', '/api/tasks', $headers(), $body(['title' => 'No token']));
    $assert($status === 403, 'Create without CSRF token returns 403');
    [$status] = $call($router, 'POST', '/api/tasks', $headers(str_repeat('0', 64)), $body(['title' => 'Bad token']));
    $assert($status === 403, 'Create with invalid CSRF token returns 403');

    $session->set('auth.user_id', $userA->id);
    [$status] = $call($router, 'POST', '/api/tasks', $headers($token), $body(['title' => '   ']));
    $assert($status === 422, 'Create validates required non-empty title');
    [$status] = $call($router, 'POST', '/api/tasks', $headers($token), '{invalid');
    $assert($status === 400, 'Create rejects invalid JSON');
    [$status] = $call($router, 'POST', '/api/tasks', ['X-CSRF-Token' => $token, 'Content-Type' => 'text/plain'], '{}');
    $assert($status === 400, 'Create rejects wrong Content-Type');

    [$status, $createBody, $created] = $call($router, 'POST', '/api/tasks', $headers($token), $body([
        'title' => '  Task A  ',
        'description' => '  Initial description  ',
        'due_at' => '2026-09-27 18:00:00',
    ]));
    $taskAId = $created['data']['task']['id'] ?? null;
    $assert($status === 201 && is_int($taskAId), 'Create returns 201 and a task');
    $assert($created['data']['task']['title'] === 'Task A' && $created['data']['task']['status'] === 'pending', 'Create trims title and defaults status to pending');
    $assert($created['data']['task']['description'] === 'Initial description' && $created['data']['task']['due_at'] === '2026-09-27 18:00:00.000000', 'Create normalizes optional fields and due date');
    $assert(array_key_exists('due_at_utc', $created['data']['task']) && $created['data']['task']['due_at_utc'] === null, 'Legacy-only create returns a null canonical UTC due date');
    $assert(array_key_exists('project_id', $created['data']['task']) && $created['data']['task']['project_id'] === null, 'Create without project returns a null project_id');
    $assert(!array_key_exists('user_id', $created['data']['task']), 'Public task representation omits internal user_id');
    $assert(!str_contains($createBody, 'password') && !str_contains($createBody, 'csrf'), 'Task response excludes auth and CSRF data');

    [$status, , $assignedCreate] = $call($router, 'POST', '/api/tasks', $headers($token), $body([
        'title' => 'Task with Project A',
        'project_id' => $projectA->id,
    ]));
    $assert($status === 201 && ($assignedCreate['data']['task']['project_id'] ?? null) === $projectA->id, 'Create with own project succeeds and returns project_id');
    $assignedCreateId = $assignedCreate['data']['task']['id'] ?? null;
    if (is_int($assignedCreateId)) {
        $tasksRepo = new App\Repositories\TaskRepository($database);
        $tasksRepo->deleteForUser($assignedCreateId, $userA->id);
    }

    [$status, , $canonicalCreate] = $call($router, 'POST', '/api/tasks', $headers($token), $body([
        'title' => 'Task with canonical due date',
        'due_at_utc' => '2026-09-30T18:30:00+03:30',
    ]));
    $canonicalCreateId = $canonicalCreate['data']['task']['id'] ?? null;
    $assert($status === 201 && is_int($canonicalCreateId), 'Create accepts a timezone-explicit canonical due date');
    $assert(($canonicalCreate['data']['task']['due_at_utc'] ?? null) === '2026-09-30T15:00:00Z', 'Create normalizes canonical due date to UTC RFC3339');
    $assert(array_key_exists('due_at', $canonicalCreate['data']['task']) && $canonicalCreate['data']['task']['due_at'] === null, 'Canonical create leaves legacy due_at null');
    if (is_int($canonicalCreateId)) {
        $tasksRepo = new App\Repositories\TaskRepository($database);
        $tasksRepo->deleteForUser($canonicalCreateId, $userA->id);
    }

    foreach ([['abc', 'string'], [-1, 'negative'], [1.5, 'float']] as [$invalidProjectId, $label]) {
        [$status] = $call($router, 'POST', '/api/tasks', $headers($token), $body([
            'title' => 'Invalid project id',
            'project_id' => $invalidProjectId,
        ]));
        $assert($status === 422, 'Create rejects ' . $label . ' project_id');
    }
    [$status] = $call($router, 'POST', '/api/tasks', $headers($token), $body(['title' => 'Missing project', 'project_id' => 999999999]));
    $assert($status === 404, 'Create with nonexistent project returns 404');
    [$status] = $call($router, 'POST', '/api/tasks', $headers($token), $body(['title' => 'Foreign project', 'project_id' => $projectB->id]));
    $assert($status === 404, 'Create with another user project returns 404');

    [$status, , $listA, $taskListCacheControl] = $call($router, 'GET', '/api/tasks');
    $assert($status === 200 && count($listA['data']['tasks'] ?? []) === 1, 'Authenticated list returns own tasks');
    $assert($taskListCacheControl === 'no-store', 'Authenticated task list disables caching');
    [$status] = $call($router, 'GET', '/api/tasks/999999999');
    $assert($status === 404, 'Nonexistent task returns 404');
    [$status, , $ownTask] = $call($router, 'GET', '/api/tasks/' . $taskAId);
    $assert($status === 200 && ($ownTask['data']['task']['id'] ?? null) === $taskAId, 'Get own task succeeds through route parameter');

    [$status, , $canonicalUpdate] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body([
        'due_at_utc' => '2026-10-01T00:00:00Z',
    ]));
    $assert($status === 200 && ($canonicalUpdate['data']['task']['due_at_utc'] ?? null) === '2026-10-01T00:00:00Z', 'Update accepts canonical UTC due date');
    $assert(($canonicalUpdate['data']['task']['due_at'] ?? null) === '2026-09-27 18:00:00.000000', 'Canonical update preserves the legacy due_at value');

    [$status] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers(), $body(['title' => 'No token']));
    $assert($status === 403, 'PUT without CSRF token returns 403');
    [$status] = $call($router, 'DELETE', '/api/tasks/' . $taskAId, []);
    $assert($status === 403, 'DELETE without CSRF token returns 403');

    [$status, , $updated] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body([
        'title' => 'Updated Task A',
        'description' => 'Updated description',
        'due_at' => null,
    ]));
    $assert($status === 200 && $updated['data']['task']['title'] === 'Updated Task A', 'PUT partially updates title');
    $assert($updated['data']['task']['description'] === 'Updated description' && $updated['data']['task']['due_at'] === null, 'PUT updates description and clears due date');
    $assert(($updated['data']['task']['due_at_utc'] ?? null) === '2026-10-01T00:00:00Z', 'Legacy due_at clear preserves an omitted canonical due date');
    $assert($updated['data']['task']['updated_at'] !== $created['data']['task']['updated_at'], 'Update changes updated_at');

    [$status, , $assignedUpdate] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['project_id' => $projectA->id]));
    $assert($status === 200 && ($assignedUpdate['data']['task']['project_id'] ?? null) === $projectA->id, 'Update assigns task to own project');
    [$status, , $removedAssignment] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['project_id' => null]));
    $assert($status === 200 && array_key_exists('project_id', $removedAssignment['data']['task']) && $removedAssignment['data']['task']['project_id'] === null, 'Update with null removes project assignment');
    [$status] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['project_id' => $projectB->id]));
    $assert($status === 404, 'Update to another user project returns 404');
    [$status] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['project_id' => 'abc']));
    $assert($status === 422, 'Update rejects string project_id');

    [$status] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['status' => 'archived']));
    $assert($status === 422, 'Invalid status returns 422');
    [$status] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['due_at' => '2026-02-31 25:61:00']));
    $assert($status === 422, 'Invalid due_at returns 422');
    foreach ([
        '2026-09-30T18:30:00',
        '2026-02-31T18:30:00Z',
        '2026-09-30T18:30:00-00:00',
        '2026-09-30T18:30:00.1234Z',
    ] as $invalidDueAtUtc) {
        [$status] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['due_at_utc' => $invalidDueAtUtc]));
        $assert($status === 422, 'Invalid due_at_utc returns 422: ' . $invalidDueAtUtc);
    }
    [$status, , $clearedCanonical] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['due_at_utc' => null]));
    $clearedCanonicalTask = $clearedCanonical['data']['task'] ?? null;
    $assert($status === 200
        && is_array($clearedCanonicalTask)
        && array_key_exists('due_at_utc', $clearedCanonicalTask)
        && $clearedCanonicalTask['due_at_utc'] === null,
        'Explicit null clears canonical due_at_utc');
    [$status] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), '{}');
    $assert($status === 422, 'Empty partial update returns 422');

    [$status, , $completed] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['status' => 'completed']));
    $completedAt = $completed['data']['task']['completed_at'] ?? null;
    $assert($status === 200 && $completed['data']['task']['status'] === 'completed', 'Task transitions pending to completed');
    $assert(is_string($completedAt) && $completedAt !== '', 'Completion records completed_at');
    [$status, , $stillCompleted] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['status' => 'completed']));
    $assert($status === 200 && $stillCompleted['data']['task']['completed_at'] === $completedAt, 'Repeated completion preserves original completed_at');
    [$status, , $pending] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['status' => 'pending']));
    $assert($status === 200 && $pending['data']['task']['status'] === 'pending', 'Task transitions completed to pending');
    $assert($pending['data']['task']['completed_at'] === null, 'Returning to pending clears completed_at');

    $session->set('auth.user_id', $userB->id);
    [$status, , $listB] = $call($router, 'GET', '/api/tasks');
    $assert($status === 200 && ($listB['data']['tasks'] ?? []) === [], 'User B list does not contain User A tasks');
    [$status] = $call($router, 'GET', '/api/tasks/' . $taskAId);
    $assert($status === 404, 'User B cannot GET User A task');
    [$status] = $call($router, 'PUT', '/api/tasks/' . $taskAId, $headers($token), $body(['title' => 'Hijacked']));
    $assert($status === 404, 'User B cannot UPDATE User A task');
    [$status] = $call($router, 'DELETE', '/api/tasks/' . $taskAId, $headers($token));
    $assert($status === 404, 'User B cannot DELETE User A task');

    [$status, , $createdB] = $call($router, 'POST', '/api/tasks', $headers($token), $body(['title' => 'Task B']));
    $taskBId = $createdB['data']['task']['id'] ?? null;
    $assert($status === 201 && is_int($taskBId), 'User B creates an owned task');
    [$status] = $call($router, 'GET', '/api/tasks/' . $taskBId);
    $assert($status === 200, 'User B can GET own task');

    $session->set('auth.user_id', $userA->id);
    [$status] = $call($router, 'GET', '/api/tasks/' . $taskBId);
    $assert($status === 404, 'User A cannot GET User B task');
    [$status] = $call($router, 'PUT', '/api/tasks/' . $taskBId, $headers($token), $body(['title' => 'Hijacked']));
    $assert($status === 404, 'User A cannot UPDATE User B task');
    [$status] = $call($router, 'DELETE', '/api/tasks/' . $taskBId, $headers($token));
    $assert($status === 404, 'User A cannot DELETE User B task');

    [$status, , $listA] = $call($router, 'GET', '/api/tasks');
    $assert($status === 200 && count($listA['data']['tasks'] ?? []) === 1, 'User A list includes only User A task');
    [$status, , $taskAStillOwned] = $call($router, 'GET', '/api/tasks/' . $taskAId);
    $assert($status === 200 && $taskAStillOwned['data']['task']['title'] === 'Updated Task A', 'Ownership failures did not alter User A task');

    [$status, , $orphanedTask] = $call($router, 'POST', '/api/tasks', $headers($token), $body([
        'title' => 'Survives project deletion',
        'project_id' => $projectA->id,
    ]));
    $orphanTaskId = $orphanedTask['data']['task']['id'] ?? null;
    $assert($status === 201 && is_int($orphanTaskId), 'Create task assigned to project before deletion');
    [$status] = $call($router, 'DELETE', '/api/projects/' . $projectA->id, $headers($token));
    $assert($status === 200, 'Owner can delete project with a task');
    [$status, , $survivingTask] = $call($router, 'GET', '/api/tasks/' . $orphanTaskId);
    $assert($status === 200 && array_key_exists('project_id', $survivingTask['data']['task']) && $survivingTask['data']['task']['project_id'] === null, 'Project deletion preserves task and clears project_id');

    [$status] = $call($router, 'DELETE', '/api/tasks/' . $taskAId, $headers($token));
    $assert($status === 200, 'Owner can delete own task');
    [$status] = $call($router, 'GET', '/api/tasks/' . $taskAId);
    $assert($status === 404, 'Deleted task returns 404');

    $assert($call($router, 'GET', '/api/health')[0] === 200, 'Existing API health endpoint remains functional');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test error: ' . $exception::class . ' (details withheld)';
} finally {
    try {
        if ($userA !== null) {
            $cleanup = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $cleanup->execute(['id' => $userA->id]);
        } else {
            $cleanup = $pdo->prepare('DELETE FROM users WHERE email = :email');
            $cleanup->execute(['email' => $userAEmail]);
        }

        if ($userB !== null) {
            $cleanup = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $cleanup->execute(['id' => $userB->id]);
        } else {
            $cleanup = $pdo->prepare('DELETE FROM users WHERE email = :email');
            $cleanup->execute(['email' => $userBEmail]);
        }
    } catch (Throwable) {
        $failures[] = 'Test user/task cleanup failed.';
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        try {
            $session->destroy();
        } catch (Throwable) {
            $failures[] = 'Test session cleanup failed.';
        }
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
}

if ($failures !== []) {
    fwrite(STDERR, sprintf("Task regression failed: %d assertions, %d failure(s).%s", $assertions, count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf("Task regression passed: %d assertions.%s", $assertions, PHP_EOL));
