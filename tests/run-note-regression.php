<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Repositories\NoteRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Environment::load(dirname(__DIR__) . '/.env');
$database = new Database(require dirname(__DIR__) . '/config/database.php');
$pdo = $database->connection();
$session = new Session();
$session->start();
$users = new UserRepository($database);
$notes = new NoteRepository($database);
$router = new Router();
$registerRoutes = require dirname(__DIR__) . '/routes/api.php';
$registerRoutes($router, $database);

$userA = null;
$userB = null;
$userAEmail = 'codex-note-a-' . bin2hex(random_bytes(8)) . '@example.invalid';
$userBEmail = 'codex-note-b-' . bin2hex(random_bytes(8)) . '@example.invalid';
$noteAId = null;
$noteBId = null;
$cascadeNoteId = null;
$assertions = 0;
$failures = [];

$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $label;
};
$body = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$headers = static fn (?string $token = null): array => array_filter([
    'Content-Type' => 'application/json',
    'X-CSRF-Token' => $token,
]);
$call = static function (
    Router $router,
    string $method,
    string $path,
    array $requestHeaders = [],
    string $requestBody = '',
    int $maxBodyBytes = Request::DEFAULT_MAX_BODY_BYTES,
): array {
    $response = $router->dispatch(new Request(
        $method,
        $path,
        headers: $requestHeaders,
        body: $requestBody,
        maxBodyBytes: $maxBodyBytes,
    ));
    ob_start();
    $response->send();
    $responseBody = (string) ob_get_clean();

    return [http_response_code(), $responseBody, json_decode($responseBody, true)];
};

try {
    $userA = $users->create('Notes Regression A', $userAEmail, password_hash('temporary-note-password-a', PASSWORD_DEFAULT));
    $userB = $users->create('Notes Regression B', $userBEmail, password_hash('temporary-note-password-b', PASSWORD_DEFAULT));

    [$csrfStatus, , $csrfResponse] = $call($router, 'GET', '/api/auth/csrf');
    $token = $csrfResponse['data']['csrf_token'] ?? '';
    $assert($csrfStatus === 200 && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'CSRF token is initialized');
    [$oversizedStatus, $oversizedBody, $oversizedResponse] = $call(
        $router,
        'POST',
        '/api/notes',
        $headers($token),
        $body(['title' => 'Oversized', 'content' => str_repeat('x', 256)]),
        128,
    );
    $assert($oversizedStatus === 413, 'Request body over configured limit returns 413');
    $assert(($oversizedResponse['success'] ?? true) === false
        && ($oversizedResponse['message'] ?? '') === 'Request body is too large.'
        && !str_contains($oversizedBody, 'exception'), 'Oversized body returns a generic JSON error');
    [$status] = $call($router, 'GET', '/api/notes');
    $assert($status === 401, 'Unauthenticated list returns 401');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $body(['title' => 'No user', 'content' => '']));
    $assert($status === 401, 'Unauthenticated create returns 401 with valid CSRF');
    [$status] = $call($router, 'POST', '/api/notes', $headers(), $body(['title' => 'No token', 'content' => '']));
    $assert($status === 403, 'Create without CSRF returns 403');
    [$status] = $call($router, 'POST', '/api/notes', $headers(str_repeat('0', 64)), $body(['title' => 'Bad token', 'content' => '']));
    $assert($status === 403, 'Create with invalid CSRF returns 403');

    $session->set('auth.user_id', $userA->id);
    [$status, , $emptyList] = $call($router, 'GET', '/api/notes');
    $assert($status === 200 && ($emptyList['data']['notes'] ?? null) === [], 'New user receives an empty notes list');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), '{invalid');
    $assert($status === 400, 'Invalid JSON returns 400');
    [$status] = $call($router, 'POST', '/api/notes', ['X-CSRF-Token' => $token, 'Content-Type' => 'text/plain'], '{}');
    $assert($status === 400, 'Wrong content type returns 400');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $body(['content' => 'Missing title']));
    $assert($status === 422, 'Create requires title');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $body(['title' => 'Missing content']));
    $assert($status === 422, 'Create requires content to be present');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $body(['title' => ' ', 'content' => '']));
    $assert($status === 422, 'Whitespace title is rejected');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $body(['title' => str_repeat('x', 201), 'content' => '']));
    $assert($status === 422, 'Title over 200 characters is rejected');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $body(['title' => 'Valid', 'content' => str_repeat('x', 50001)]));
    $assert($status === 422, 'Content over 50000 characters is rejected');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $body(['title' => 'Valid', 'content' => 42]));
    $assert($status === 422, 'Non-string content is rejected');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $body(['title' => 'Valid', 'content' => '', 'user_id' => $userB->id]));
    $assert($status === 422, 'user_id mass assignment is rejected');

    [$status, $createBody, $created] = $call($router, 'POST', '/api/notes', $headers($token), $body([
        'title' => '  یادداشت اول  ',
        'content' => '',
    ]));
    $noteAId = $created['data']['note']['id'] ?? null;
    $assert($status === 201 && is_int($noteAId), 'Create returns 201 with an ID');
    $assert(($created['data']['note']['title'] ?? null) === 'یادداشت اول', 'Create trims title');
    $assert(array_key_exists('content', $created['data']['note'] ?? []) && $created['data']['note']['content'] === '', 'Empty content is supported');
    $assert(!array_key_exists('user_id', $created['data']['note'] ?? []), 'Public note omits user_id');
    $assert(!preg_match('/password|csrf|user_id|password_hash/i', $createBody), 'Create response does not expose sensitive fields');

    $maximumContent = str_repeat('آ', 50000);
    $maximumPayload = $body([
        'title' => 'Unicode limits',
        'content' => $maximumContent,
    ]);
    $assert(strlen($maximumPayload) < Request::DEFAULT_MAX_BODY_BYTES, 'Valid 50000-character Note fits default body limit');
    [$status] = $call($router, 'POST', '/api/notes', $headers($token), $maximumPayload);
    $assert($status === 201, 'Exactly 50000 Unicode characters are accepted');
    $longNoteId = $notes->findAllForUser($userA->id)[0]->id ?? null;
    if (is_int($longNoteId) && $longNoteId !== $noteAId) $notes->deleteForUser($longNoteId, $userA->id);

    $escapedMaximumPayload = json_encode([
        'title' => 'Escaped Unicode limit',
        'content' => str_repeat('😀', 50000),
    ], JSON_THROW_ON_ERROR);
    $assert(strlen($escapedMaximumPayload) < Request::DEFAULT_MAX_BODY_BYTES, 'Escaped 50000-character Note fits default body limit');
    [$status, , $escapedMaximumResponse] = $call($router, 'POST', '/api/notes', $headers($token), $escapedMaximumPayload);
    $escapedNoteId = $escapedMaximumResponse['data']['note']['id'] ?? null;
    $assert($status === 201 && is_int($escapedNoteId), 'Escaped supplementary Unicode Note is accepted at the maximum length');
    if (is_int($escapedNoteId)) $notes->deleteForUser($escapedNoteId, $userA->id);

    [$status, , $listA] = $call($router, 'GET', '/api/notes');
    $assert($status === 200 && count($listA['data']['notes'] ?? []) === 1, 'List contains only the newly created user note');
    [$status] = $call($router, 'GET', '/api/notes/999999999');
    $assert($status === 404, 'Unknown note returns 404');
    [$status, , $shown] = $call($router, 'GET', '/api/notes/' . $noteAId);
    $assert($status === 200 && ($shown['data']['note']['id'] ?? null) === $noteAId, 'Owner can show own note');

    [$status] = $call($router, 'PUT', '/api/notes/' . $noteAId, $headers(), $body(['title' => 'No CSRF']));
    $assert($status === 403, 'PUT without CSRF returns 403');
    [$status] = $call($router, 'DELETE', '/api/notes/' . $noteAId, $headers(str_repeat('0', 64)));
    $assert($status === 403, 'DELETE with invalid CSRF returns 403');
    [$status, , $updated] = $call($router, 'PUT', '/api/notes/' . $noteAId, $headers($token), $body(['title' => '  Updated title  ']));
    $assert($status === 200 && ($updated['data']['note']['title'] ?? null) === 'Updated title', 'PUT partially updates and trims title');
    $assert(($updated['data']['note']['content'] ?? null) === '', 'Partial update retains omitted content');
    [$status, , $updated] = $call($router, 'PUT', '/api/notes/' . $noteAId, $headers($token), $body(['content' => "line one\nline two"]));
    $assert($status === 200 && ($updated['data']['note']['content'] ?? null) === "line one\nline two", 'PUT updates content only');
    [$status, , $updated] = $call($router, 'PUT', '/api/notes/' . $noteAId, $headers($token), $body(['content' => '']));
    $assert($status === 200 && ($updated['data']['note']['content'] ?? null) === '', 'PUT empty content clears content');
    [$status] = $call($router, 'PUT', '/api/notes/' . $noteAId, $headers($token), '{}');
    $assert($status === 422, 'Empty partial update returns 422');
    [$status] = $call($router, 'PUT', '/api/notes/' . $noteAId, $headers($token), $body(['user_id' => $userB->id]));
    $assert($status === 422, 'PUT rejects user_id');

    $session->set('auth.user_id', $userB->id);
    [$status, , $listB] = $call($router, 'GET', '/api/notes');
    $assert($status === 200 && ($listB['data']['notes'] ?? []) === [], 'Other user list is isolated');
    [$status] = $call($router, 'GET', '/api/notes/' . $noteAId);
    $assert($status === 404, 'Other user cannot show note');
    [$status] = $call($router, 'PUT', '/api/notes/' . $noteAId, $headers($token), $body(['title' => 'Changed']));
    $assert($status === 404, 'Other user cannot update note');
    [$status] = $call($router, 'DELETE', '/api/notes/' . $noteAId, $headers($token));
    $assert($status === 404, 'Other user cannot delete note');
    [$status, , $createdB] = $call($router, 'POST', '/api/notes', $headers($token), $body(['title' => 'User B note', 'content' => 'Private']));
    $noteBId = $createdB['data']['note']['id'] ?? null;
    $assert($status === 201 && is_int($noteBId), 'Second user can create own note');

    $cascadeNote = $notes->create($userA->id, 'Cascade test', '');
    $cascadeNoteId = $cascadeNote->id;
    $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $deleteUser->execute(['id' => $userA->id]);
    $userA = null;
    $cascadeCheck = $pdo->prepare('SELECT COUNT(*) FROM notes WHERE id = :id');
    $cascadeCheck->execute(['id' => $cascadeNoteId]);
    $assert((int) $cascadeCheck->fetchColumn() === 0, 'Deleting owner cascades to their notes');

    $session->set('auth.user_id', $userB->id);
    [$status, , $deleted] = $call($router, 'DELETE', '/api/notes/' . $noteBId, $headers($token));
    $assert($status === 200 && ($deleted['success'] ?? false) === true, 'Owner can delete note');
    [$status] = $call($router, 'GET', '/api/notes/' . $noteBId);
    $assert($status === 404, 'Deleted note returns 404');
    [$status] = $call($router, 'GET', '/api/health');
    $assert($status === 200, 'Existing health endpoint remains available');
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
        $failures[] = 'Test data cleanup failed.';
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        try {
            $session->destroy();
        } catch (Throwable) {
            $failures[] = 'Test session cleanup failed.';
        }
    }
}

foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
if ($failures !== []) {
    fwrite(STDERR, sprintf("Notes regression failed: %d assertions, %d failure(s).%s", $assertions, count($failures), PHP_EOL));
    exit(1);
}
fwrite(STDOUT, sprintf("Notes regression passed: %d assertions.%s", $assertions, PHP_EOL));
