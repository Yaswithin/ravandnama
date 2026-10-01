<?php

declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Environment;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Controllers\AuthController;
use App\Repositories\UserRepository;
use App\Services\AuthService;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Environment::load(dirname(__DIR__) . '/.env');
$database = new Database(require dirname(__DIR__) . '/config/database.php');
$pdo = $database->connection();
$users = new UserRepository($database);
$session = new Session();
$session->start();
$csrf = new Csrf($session);
$auth = new Auth($session, $users);
$service = new AuthService($users, $session);
$controller = new AuthController($service, $auth, $session);
$router = new Router();
$registerRoutes = require dirname(__DIR__) . '/routes/api.php';
$registerRoutes($router, $database);

$email = 'codex-auth-regression-' . bin2hex(random_bytes(8)) . '@example.invalid';
$rawPassword = 'Regression-password-482!';
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

$jsonHeaders = static fn (?string $token = null): array => array_filter([
    'Content-Type' => 'application/json',
    'X-CSRF-Token' => $token,
]);

try {
    $cookieParams = session_get_cookie_params();
    $assert((string) ini_get('session.use_strict_mode') === '1', 'Session strict mode enabled');
    $assert($cookieParams['httponly'] === true, 'Session cookie HttpOnly');
    $assert(strtolower((string) ($cookieParams['samesite'] ?? '')) === 'lax', 'Session cookie SameSite=Lax');
    $assert($cookieParams['secure'] === false, 'Session cookie Secure disabled on local HTTP');
    $assert(realpath((string) ini_get('session.save_path')) === realpath(dirname(__DIR__) . '/storage/sessions'), 'Private session save path');
    $assert($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false, 'PDO native prepared statements configured');

    $originalHttps = $_SERVER['HTTPS'] ?? null;
    $originalPort = $_SERVER['SERVER_PORT'] ?? null;
    $session->destroy();
    $_SERVER['HTTPS'] = 'on';
    $httpsSession = new Session();
    $httpsSession->start();
    $assert(session_get_cookie_params()['secure'] === true, 'Session cookie Secure enabled on HTTPS');
    $httpsSession->destroy();
    if ($originalHttps === null) {
        unset($_SERVER['HTTPS']);
    } else {
        $_SERVER['HTTPS'] = $originalHttps;
    }
    if ($originalPort === null) {
        unset($_SERVER['SERVER_PORT']);
    } else {
        $_SERVER['SERVER_PORT'] = $originalPort;
    }
    session_id(bin2hex(random_bytes(16)));
    $session->start();

    [$status, $csrfBody, $csrfData, $csrfCacheControl] = $call($router, 'GET', '/api/auth/csrf');
    $token = $csrfData['data']['csrf_token'] ?? '';
    $assert($status === 200 && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1, 'CSRF endpoint returns a 32-byte random token');
    $assert($csrfCacheControl === 'no-store', 'CSRF response disables caching');
    $assert($session->get('csrf.token') === $token, 'CSRF token stored in session');
    $assert(!str_contains($csrfBody, session_id()), 'Session ID is absent from CSRF JSON response');

    [$status] = $call($router, 'GET', '/api/auth/me');
    $assert($status === 401, 'Me returns 401 before any registration');

    [$status] = $call($router, 'POST', '/api/auth/register', $jsonHeaders(), json_encode([
        'name' => 'Regression User', 'email' => $email, 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 403, 'Register rejects missing CSRF token');
    $assert(!$session->has('auth.user_id'), 'Register without CSRF token does not authenticate a user');

    [$status] = $call($router, 'POST', '/api/auth/register', $jsonHeaders(str_repeat('0', 64)), json_encode([
        'name' => 'Regression User', 'email' => $email, 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 403, 'Register rejects invalid CSRF token');
    $assert(!$session->has('auth.user_id'), 'Register with invalid CSRF token does not authenticate a user');

    $sessionIdBeforeRegister = session_id();
    [$status, $body, $data] = $call($router, 'POST', '/api/auth/register', $jsonHeaders($token), json_encode([
        'name' => 'Regression User', 'email' => $email, 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 201, 'Register succeeds with valid CSRF token');
    $assert(!str_contains($body, $rawPassword) && !str_contains($body, 'password_hash') && !str_contains($body, session_id()), 'Register response excludes password, hash, and session ID');

    $registeredUserId = $data['data']['user']['id'] ?? null;
    $assert(is_int($registeredUserId) && $registeredUserId > 0, 'Register response exposes the created user ID');
    $assert(session_id() !== $sessionIdBeforeRegister, 'Register regenerates session ID');
    $assert($session->get('auth.user_id') === $registeredUserId, 'Register stores the created user ID in the session');
    $assert($session->get('csrf.token') === $token, 'CSRF token survives registration session ID regeneration');

    [$meStatus, , $meData, $meCacheControl] = $call($router, 'GET', '/api/auth/me');
    $assert($meStatus === 200, 'Me succeeds immediately after registration without a separate login');
    $assert(($meData['data']['user']['id'] ?? null) === $registeredUserId, 'Me returns the just-registered user');
    $assert($meCacheControl === 'no-store', 'Post-registration Me response disables caching');

    $storedHash = $pdo->prepare('SELECT password_hash FROM users WHERE email = :email');
    $storedHash->execute(['email' => $email]);
    $hash = $storedHash->fetchColumn();
    $assert(is_string($hash) && $hash !== $rawPassword && password_verify($rawPassword, $hash), 'Database stores verifiable password hash, not raw password');

    [$status] = $call($router, 'POST', '/api/auth/register', $jsonHeaders($token), json_encode([
        'name' => 'Duplicate', 'email' => $email, 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 409, 'Duplicate email returns 409');

    [$status] = $call($router, 'POST', '/api/auth/register', $jsonHeaders($token), '{invalid');
    $assert($status === 400, 'Register rejects invalid JSON');
    [$status] = $call($router, 'POST', '/api/auth/register', ['X-CSRF-Token' => $token, 'Content-Type' => 'text/plain'], '{}');
    $assert($status === 400, 'Register rejects wrong Content-Type');
    [$status] = $call($router, 'POST', '/api/auth/register', $jsonHeaders($token), json_encode([
        'name' => ' ', 'email' => $email, 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 422, 'Register validates blank name');
    [$status] = $call($router, 'POST', '/api/auth/register', $jsonHeaders($token), json_encode([
        'name' => 'Regression User', 'email' => $email, 'password' => 'short',
    ], JSON_THROW_ON_ERROR));
    $assert($status === 422, 'Register validates password length');
    [$status] = $call($router, 'POST', '/api/auth/register', $jsonHeaders($token), json_encode([
        'name' => 'Regression User', 'email' => 'not-an-email', 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 422, 'Register validates email');
    $assert($session->get('auth.user_id') === $registeredUserId, 'Failed registrations leave the authenticated user unchanged');

    $sessionIdBeforeLogin = session_id();
    [$status, $loginBody] = $call($router, 'POST', '/api/auth/login', $jsonHeaders($token), json_encode([
        'email' => strtoupper($email), 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 200, 'Login accepts normalized uppercase email');
    $assert(session_id() !== $sessionIdBeforeLogin, 'Login regenerates session ID');
    $assert($session->get('auth.user_id') !== null, 'Login stores user ID in session');
    $assert($session->get('csrf.token') === $token, 'CSRF token survives session ID regeneration');
    $assert(!str_contains($loginBody, $rawPassword) && !str_contains($loginBody, 'password_hash') && !str_contains($loginBody, session_id()), 'Login response excludes password, hash, and session ID');

    [$status, $meBody, , $meCacheControl] = $call($router, 'GET', '/api/auth/me');
    $assert($status === 200, 'Me returns authenticated user without CSRF header');
    $assert($meCacheControl === 'no-store', 'Authenticated Me response disables caching');
    $assert(!str_contains($meBody, 'password_hash') && !str_contains($meBody, $rawPassword), 'Me response excludes password material');

    [$status, $wrongPasswordBody] = $call($router, 'POST', '/api/auth/login', $jsonHeaders($token), json_encode([
        'email' => $email, 'password' => 'wrong-password',
    ], JSON_THROW_ON_ERROR));
    $assert($status === 401, 'Wrong password uses generic invalid credentials response');
    [$status, $unknownEmailBody] = $call($router, 'POST', '/api/auth/login', $jsonHeaders($token), json_encode([
        'email' => 'unknown-' . $email, 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 401, 'Unknown email uses generic invalid credentials response');
    $assert($wrongPasswordBody === $unknownEmailBody, 'Wrong password and unknown email return the same public error');
    [$status] = $call($router, 'POST', '/api/auth/login', $jsonHeaders($token), json_encode([
        'email' => '', 'password' => $rawPassword,
    ], JSON_THROW_ON_ERROR));
    $assert($status === 422, 'Login validates malformed email');
    [$status] = $call($router, 'POST', '/api/auth/login', $jsonHeaders($token), '{invalid');
    $assert($status === 400, 'Login rejects invalid JSON');
    [$status] = $call($router, 'POST', '/api/auth/login', ['X-CSRF-Token' => $token, 'Content-Type' => 'text/plain'], '{}');
    $assert($status === 400, 'Login rejects wrong Content-Type');

    $session->set('auth.user_id', 99999999999999);
    [$status] = $call($router, 'GET', '/api/auth/me');
    $assert($status === 401 && !$session->has('auth.user_id'), 'Me clears a session for a nonexistent user');
    $session->set('auth.user_id', (int) $data['data']['user']['id']);

    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $guarded = new Router();
        $guarded->add($method, '/guarded', $csrf->protect(static fn (Request $request): Response => Response::json(['success' => true])));
        [$missingStatus] = $call($guarded, $method, '/guarded');
        [$invalidStatus] = $call($guarded, $method, '/guarded', ['X-CSRF-Token' => str_repeat('0', 64)]);
        [$validStatus] = $call($guarded, $method, '/guarded', ['X-CSRF-Token' => $token]);
        $assert($missingStatus === 403 && $invalidStatus === 403 && $validStatus === 200, $method . ' CSRF wrapper handles missing, invalid, and valid token');
    }

    [$status] = $call($router, 'POST', '/api/auth/logout', $jsonHeaders());
    $assert($status === 403, 'Logout rejects missing CSRF token');
    [$status] = $call($router, 'POST', '/api/auth/logout', $jsonHeaders(str_repeat('0', 64)));
    $assert($status === 403, 'Logout rejects invalid CSRF token');
    [$status, $logoutBody] = $call($router, 'POST', '/api/auth/logout', $jsonHeaders($token));
    $assert($status === 200 && !$session->has('csrf.token'), 'Logout succeeds and destroys CSRF token');
    $assert(!str_contains($logoutBody, session_id()), 'Logout JSON excludes session ID');
    [$status] = $call($router, 'GET', '/api/auth/me');
    $assert($status === 401, 'Me returns 401 after logout');

    [$status, , $anonCsrf] = $call($router, 'GET', '/api/auth/csrf');
    $assert($status === 200, 'CSRF endpoint is reachable after logout');
    $logoutStatus = null;

    if ($status === 200) {
        $reloginToken = $anonCsrf['data']['csrf_token'] ?? null;
        $sessionIdBeforeRelogin = session_id();
        [$loginStatus, , $reloginData] = $call($router, 'POST', '/api/auth/login', $jsonHeaders($reloginToken), json_encode([
            'email' => $email, 'password' => $rawPassword,
        ], JSON_THROW_ON_ERROR));
        $assert($loginStatus === 200, 'Login still succeeds after register then logout');
        $assert(session_id() !== $sessionIdBeforeRelogin, 'Post-logout login regenerates session ID');
        $assert($session->get('auth.user_id') === $registeredUserId, 'Post-logout login stores the same user ID in the session');
        $assert(($reloginData['data']['user']['id'] ?? null) === $registeredUserId, 'Post-logout login returns the same user');

        [$reloginMeStatus] = $call($router, 'GET', '/api/auth/me');
        $assert($reloginMeStatus === 200, 'Me succeeds after post-logout login');

        [$logoutStatus] = $call($router, 'POST', '/api/auth/logout', $jsonHeaders($reloginToken));
    }

    $assert($logoutStatus === 200, 'Unauthenticated logout has a predictable successful response');

    $logSources = (string) file_get_contents(dirname(__DIR__) . '/app/Core/Csrf.php')
        . (string) file_get_contents(dirname(__DIR__) . '/routes/api.php');
    $assert(!preg_match('/error_log\([^\n]*(?:token|session_id)/i', $logSources), 'CSRF token and session ID are not written to logs');
    $repositorySource = (string) file_get_contents(dirname(__DIR__) . '/app/Repositories/UserRepository.php');
    $assert(str_contains($repositorySource, '->prepare('), 'User SQL uses prepared statements');
    $assert(!str_contains($repositorySource, 'App\\Services\\'), 'Repository has no Service-layer dependency');

    $brokenDatabase = new Database([
        'host' => '127.0.0.1',
        'port' => '1',
        'database' => 'not_used',
        'username' => 'not_used',
        'password' => 'must-not-leak-this-secret',
    ]);
    $brokenDatabaseRouter = new Router();
    $registerRoutes($brokenDatabaseRouter, $brokenDatabase);
    [$status, $errorBody] = $call($brokenDatabaseRouter, 'GET', '/api/health/db');
    $assert($status === 503, 'Database connection failure returns safe 503');
    $assert(!str_contains($errorBody, 'must-not-leak-this-secret') && !str_contains($errorBody, 'PDOException'), 'Database error response hides credentials and internal exception details');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test error: ' . $exception::class . ' (details withheld)';
} finally {
    try {
        $cleanup = $pdo->prepare('DELETE FROM users WHERE email = :email');
        $cleanup->execute(['email' => $email]);
    } catch (Throwable) {
        $failures[] = 'Test user cleanup failed.';
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
    fwrite(STDERR, sprintf("Auth regression failed: %d assertions, %d failure(s).%s", $assertions, count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf("Auth regression passed: %d assertions.%s", $assertions, PHP_EOL));
