<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Repositories\UserRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Environment::load(dirname(__DIR__) . '/.env');
$database = new Database(require dirname(__DIR__) . '/config/database.php');
$pdo = $database->connection();
$session = new Session();
$session->start();
$users = new UserRepository($database);
$router = new Router();
$registerRoutes = require dirname(__DIR__) . '/routes/api.php';
$registerRoutes($router, $database);

$userA = null;
$userB = null;
$userAEmail = 'codex-project-a-' . bin2hex(random_bytes(8)) . '@example.invalid';
$userBEmail = 'codex-project-b-' . bin2hex(random_bytes(8)) . '@example.invalid';
$projectAId = null;
$projectBId = null;
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

try {
    $userA = $users->create('Project Regression A', $userAEmail, password_hash('test-project-password-a', PASSWORD_DEFAULT));
    $userB = $users->create('Project Regression B', $userBEmail, password_hash('test-project-password-b', PASSWORD_DEFAULT));

    [$csrfStatus, , $csrfResponse] = $call($router, 'GET', '/api/auth/csrf');
    $token = $csrfResponse['data']['csrf_token'] ?? '';
    $assert($csrfStatus === 200 && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'CSRF endpoint returns a token');

    [$status] = $call($router, 'GET', '/api/projects');
    $assert($status === 401, 'Unauthenticated project list returns 401');
    [$status] = $call($router, 'GET', '/api/projects/1');
    $assert($status === 401, 'Unauthenticated project detail returns 401');
    [$status] = $call($router, 'POST', '/api/projects', $headers($token), $body(['name' => 'Unauthenticated']));
    $assert($status === 401, 'Unauthenticated project create returns 401 with valid CSRF');
    [$status] = $call($router, 'PUT', '/api/projects/1', $headers($token), $body(['name' => 'Unauthenticated']));
    $assert($status === 401, 'Unauthenticated project update returns 401 with valid CSRF');
    [$status] = $call($router, 'DELETE', '/api/projects/1', $headers($token));
    $assert($status === 401, 'Unauthenticated project delete returns 401 with valid CSRF');

    [$status] = $call($router, 'POST', '/api/projects', $headers(), $body(['name' => 'No token']));
    $assert($status === 403, 'Project POST without CSRF returns 403');
    [$status] = $call($router, 'POST', '/api/projects', $headers(str_repeat('0', 64)), $body(['name' => 'Bad token']));
    $assert($status === 403, 'Project POST with invalid CSRF returns 403');
    [$status] = $call($router, 'PUT', '/api/projects/1', $headers(), $body(['name' => 'No token']));
    $assert($status === 403, 'Project PUT without CSRF returns 403');
    [$status] = $call($router, 'DELETE', '/api/projects/1');
    $assert($status === 403, 'Project DELETE without CSRF returns 403');

    $session->set('auth.user_id', $userA->id);

    [$status] = $call($router, 'POST', '/api/projects', $headers($token), '{}');
    $assert($status === 422, 'Project name is required');
    [$status] = $call($router, 'POST', '/api/projects', $headers($token), $body(['name' => '  ']));
    $assert($status === 422, 'Project name cannot be empty');
    [$status] = $call($router, 'POST', '/api/projects', $headers($token), $body(['name' => 42]));
    $assert($status === 422, 'Project name must be a string');
    [$status] = $call($router, 'POST', '/api/projects', $headers($token), $body(['name' => str_repeat('x', 201)]));
    $assert($status === 422, 'Project name length is limited to 200 characters');
    [$status] = $call($router, 'POST', '/api/projects', $headers($token), $body([
        'name' => 'Valid name', 'description' => ['invalid'],
    ]));
    $assert($status === 422, 'Project description must be a string or null');
    [$status] = $call($router, 'POST', '/api/projects', $headers($token), $body([
        'name' => 'Invalid owner', 'user_id' => $userB->id,
    ]));
    $assert($status === 422, 'Client cannot provide user_id when creating a Project');

    [$status, $createBody, $created] = $call($router, 'POST', '/api/projects', $headers($token), $body([
        'name' => '  Ravandnama  ',
        'description' => '  Personal Life OS project  ',
    ]));
    $projectAId = $created['data']['project']['id'] ?? null;
    $assert($status === 201 && is_int($projectAId), 'Project creation returns 201');
    $assert($created['data']['project']['name'] === 'Ravandnama', 'Project name is trimmed');
    $assert($created['data']['project']['description'] === 'Personal Life OS project', 'Project description is trimmed');
    $assert(is_string($created['data']['project']['created_at'] ?? null) && $created['data']['project']['created_at'] !== '', 'created_at is returned');
    $assert(!array_key_exists('user_id', $created['data']['project']), 'Public Project representation excludes user_id');
    $assert(!str_contains($createBody, 'password') && !str_contains($createBody, 'csrf'), 'Project response excludes authentication data');

    [$status, , $listA, $projectListCacheControl] = $call($router, 'GET', '/api/projects');
    $assert($status === 200 && count($listA['data']['projects'] ?? []) === 1, 'List returns User A projects');
    $assert($projectListCacheControl === 'no-store', 'Authenticated project list disables caching');
    [$status, , $ownProject] = $call($router, 'GET', '/api/projects/' . $projectAId);
    $assert($status === 200 && ($ownProject['data']['project']['id'] ?? null) === $projectAId, 'User A can get own Project');
    [$status] = $call($router, 'GET', '/api/projects/999999999');
    $assert($status === 404, 'Nonexistent Project returns 404');

    [$status, , $updated] = $call($router, 'PUT', '/api/projects/' . $projectAId, $headers($token), $body([
        'name' => 'Ravandnama OS',
    ]));
    $assert($status === 200 && $updated['data']['project']['name'] === 'Ravandnama OS', 'PUT partially updates the Project name');
    $assert($updated['data']['project']['description'] === 'Personal Life OS project', 'Omitted description is retained');
    $assert($updated['data']['project']['updated_at'] !== $created['data']['project']['updated_at'], 'updated_at changes on update');
    [$status] = $call($router, 'PUT', '/api/projects/' . $projectAId, $headers($token), $body([
        'name' => 'Invalid reassignment', 'user_id' => $userB->id,
    ]));
    $assert($status === 422, 'Client cannot change Project user_id');
    [$status, , $descriptionCleared] = $call($router, 'PUT', '/api/projects/' . $projectAId, $headers($token), $body([
        'description' => '',
    ]));
    $assert($status === 200 && $descriptionCleared['data']['project']['description'] === null, 'Empty description is normalized to null');
    [$status] = $call($router, 'PUT', '/api/projects/' . $projectAId, $headers($token), '{}');
    $assert($status === 422, 'Empty partial update returns 422');

    $session->set('auth.user_id', $userB->id);
    [$status, , $listB] = $call($router, 'GET', '/api/projects');
    $assert($status === 200 && ($listB['data']['projects'] ?? []) === [], 'User B list does not contain User A Project');
    [$status] = $call($router, 'GET', '/api/projects/' . $projectAId);
    $assert($status === 404, 'User B cannot GET User A Project');
    [$status] = $call($router, 'PUT', '/api/projects/' . $projectAId, $headers($token), $body(['name' => 'Hijacked']));
    $assert($status === 404, 'User B cannot UPDATE User A Project');
    [$status] = $call($router, 'DELETE', '/api/projects/' . $projectAId, $headers($token));
    $assert($status === 404, 'User B cannot DELETE User A Project');

    [$status, , $createdB] = $call($router, 'POST', '/api/projects', $headers($token), $body(['name' => 'Project B']));
    $projectBId = $createdB['data']['project']['id'] ?? null;
    $assert($status === 201 && is_int($projectBId), 'User B creates an owned Project');
    [$status] = $call($router, 'GET', '/api/projects/' . $projectBId);
    $assert($status === 200, 'User B can access own Project');

    $session->set('auth.user_id', $userA->id);
    [$status] = $call($router, 'GET', '/api/projects/' . $projectBId);
    $assert($status === 404, 'User A cannot GET User B Project');
    [$status] = $call($router, 'PUT', '/api/projects/' . $projectBId, $headers($token), $body(['name' => 'Hijacked']));
    $assert($status === 404, 'User A cannot UPDATE User B Project');
    [$status] = $call($router, 'DELETE', '/api/projects/' . $projectBId, $headers($token));
    $assert($status === 404, 'User A cannot DELETE User B Project');
    [$status, , $stillOwned] = $call($router, 'GET', '/api/projects/' . $projectAId);
    $assert($status === 200 && $stillOwned['data']['project']['name'] === 'Ravandnama OS', 'User A retains access to own Project');

    [$status] = $call($router, 'DELETE', '/api/projects/' . $projectAId, $headers($token));
    $assert($status === 200, 'Owner can delete own Project');
    [$status] = $call($router, 'GET', '/api/projects/' . $projectAId);
    $assert($status === 404, 'Deleted Project returns 404');

    $foreignKey = $pdo->prepare(<<<'SQL'
        SELECT rc.DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS rc
        WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
          AND rc.CONSTRAINT_NAME = 'projects_user_id_foreign'
        SQL);
    $foreignKey->execute();
    $assert($foreignKey->fetchColumn() === 'CASCADE', 'Project user foreign key cascades on user deletion');

    $columnCheck = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'project_id'");
    $assert($columnCheck->fetchColumn() === 'YES', 'Task project_id relationship is nullable');
    $taskForeignKey = $pdo->query(<<<'SQL'
        SELECT rc.DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS rc
        WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
          AND rc.CONSTRAINT_NAME = 'tasks_project_id_foreign'
        SQL);
    $assert($taskForeignKey->fetchColumn() === 'SET NULL', 'Deleting a Project sets Task project_id to null');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test error: ' . $exception::class . ' (details withheld)';
} finally {
    foreach ([[$userA, $userAEmail], [$userB, $userBEmail]] as [$user, $email]) {
        try {
            if ($user !== null) {
                $cleanup = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $cleanup->execute(['id' => $user->id]);
            } else {
                $cleanup = $pdo->prepare('DELETE FROM users WHERE email = :email');
                $cleanup->execute(['email' => $email]);
            }
        } catch (Throwable) {
            $failures[] = 'Test user/Project cleanup failed.';
        }
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
    fwrite(STDERR, sprintf("Project regression failed: %d assertions, %d failure(s).%s", $assertions, count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf("Project regression passed: %d assertions.%s", $assertions, PHP_EOL));
