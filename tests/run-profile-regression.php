<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;
use App\Models\User;
use App\Repositories\UserRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

Environment::load(dirname(__DIR__) . '/.env');
$database = new Database(require dirname(__DIR__) . '/config/database.php');
$pdo = $database->connection();
$users = new UserRepository($database);
$session = new Session();
$session->start();
$router = new Router();
$registerRoutes = require dirname(__DIR__) . '/routes/api.php';
$registerRoutes($router, $database);

$emailA = 'codex-profile-a-' . bin2hex(random_bytes(8)) . '@example.invalid';
$emailB = 'codex-profile-b-' . bin2hex(random_bytes(8)) . '@example.invalid';
$userA = null;
$userB = null;
$registeredUserId = null;
$assertions = 0;
$failures = [];

$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $label;
};

$call = static function (Router $router, string $method, string $path, array $headers = [], string $body = ''): array {
    $response = $router->dispatch(new Request($method, $path, headers: $headers, body: $body));
    ob_start();
    $response->send();
    $responseBody = (string) ob_get_clean();
    return [http_response_code(), $responseBody, json_decode($responseBody, true)];
};

$json = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$headers = static fn (?string $token = null): array => array_filter([
    'Content-Type' => 'application/json',
    'X-CSRF-Token' => $token,
]);

try {
    $migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/202609260006_add_timezone_to_users_table.php');
    $assert(str_contains($migration, 'ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT \'Asia/Tehran\''), 'Migration adds a non-null timezone with the application default');
    $column = $pdo->query("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'timezone'")->fetch(PDO::FETCH_ASSOC);
    $defaultIsExpected = is_array($column)
        && in_array($column['COLUMN_DEFAULT'], [User::DEFAULT_TIMEZONE, "'" . User::DEFAULT_TIMEZONE . "'"], true);
    $assert(is_array($column) && $column['COLUMN_TYPE'] === 'varchar(64)' && $column['IS_NULLABLE'] === 'NO' && $defaultIsExpected, 'Applied schema has the expected timezone column and default');

    $userA = $users->create('Profile Regression A', $emailA, password_hash('profile-test-password-a', PASSWORD_DEFAULT));
    $userB = $users->create('Profile Regression B', $emailB, password_hash('profile-test-password-b', PASSWORD_DEFAULT));
    $assert($userA->timezone === User::DEFAULT_TIMEZONE && $userB->timezone === User::DEFAULT_TIMEZONE, 'New repository users receive the default timezone');

    $csrfResponse = $router->dispatch(new Request('GET', '/api/auth/csrf'));
    ob_start();
    $csrfResponse->send();
    $csrf = json_decode((string) ob_get_clean(), true)['data']['csrf_token'] ?? null;
    $assert(is_string($csrf) && preg_match('/\A[a-f0-9]{64}\z/', $csrf) === 1, 'CSRF token is available for profile mutations');

    [$registerStatus, , $registered] = $call($router, 'POST', '/api/auth/register', $headers($csrf), $json([
        'name' => 'Timezone Default Regression',
        'email' => 'codex-profile-default-' . bin2hex(random_bytes(6)) . '@example.invalid',
        'password' => 'timezone-test-password',
    ]));
    $registeredUserId = $registered['data']['user']['id'] ?? null;
    $assert($registerStatus === 201 && ($registered['data']['user']['timezone'] ?? null) === User::DEFAULT_TIMEZONE, 'Registered users receive and expose the application timezone default');

    // Registration now authenticates the new user in the session, so return this
    // session to the unauthenticated state before asserting protected-route behavior.
    $session->remove('auth.user_id');

    [$status] = $call($router, 'GET', '/api/auth/timezones');
    $assert($status === 401, 'Timezone list requires authentication');
    [$status] = $call($router, 'PUT', '/api/auth/me', $headers($csrf), $json(['timezone' => 'Europe/Paris']));
    $assert($status === 401, 'Unauthenticated timezone update is rejected');
    [$status] = $call($router, 'PUT', '/api/auth/me', $headers(), $json(['timezone' => 'Europe/Paris']));
    $assert($status === 403, 'Timezone update enforces CSRF');

    $session->set('auth.user_id', $userA->id);
    [$status, , $meA] = $call($router, 'GET', '/api/auth/me');
    $assert($status === 200 && ($meA['data']['user']['timezone'] ?? null) === User::DEFAULT_TIMEZONE, 'Authenticated user can read their timezone');
    [$status, , $timezoneList] = $call($router, 'GET', '/api/auth/timezones');
    $available = $timezoneList['data']['timezones'] ?? [];
    $assert($status === 200 && in_array('Asia/Tehran', $available, true) && in_array('Europe/Paris', $available, true), 'Authenticated timezone list includes valid IANA identifiers');

    [$status] = $call($router, 'PUT', '/api/auth/me', $headers($csrf), $json(['timezone' => 'Not/A_Timezone']));
    $assert($status === 422, 'Invalid timezone is rejected');
    [$status] = $call($router, 'PUT', '/api/auth/me', $headers($csrf), $json(['timezone' => 'Europe/Paris', 'user_id' => $userB->id]));
    $assert($status === 422, 'Profile update rejects attempts to provide another user ID');
    $assert($users->findById($userA->id)?->timezone === User::DEFAULT_TIMEZONE, 'Rejected profile changes do not alter stored timezone');

    [$status, , $updatedA] = $call($router, 'PUT', '/api/auth/me', $headers($csrf), $json(['timezone' => 'Europe/Paris']));
    $assert($status === 200 && ($updatedA['data']['user']['timezone'] ?? null) === 'Europe/Paris', 'Authenticated user can update their timezone');
    $assert(($updatedA['data']['user']['id'] ?? null) === $userA->id, 'Update response contains only the authenticated user profile');

    $session->set('auth.user_id', $userB->id);
    [$status, , $meB] = $call($router, 'GET', '/api/auth/me');
    $assert($status === 200
        && ($meB['data']['user']['timezone'] ?? null) === User::DEFAULT_TIMEZONE
        && ($meB['data']['user']['id'] ?? null) === $userB->id,
        'Another user can read only their own profile timezone');
    [$status, , $updatedB] = $call($router, 'PUT', '/api/auth/me', $headers($csrf), $json(['timezone' => 'Asia/Tokyo']));
    $assert($status === 200 && ($updatedB['data']['user']['timezone'] ?? null) === 'Asia/Tokyo', 'Second authenticated user updates only their own timezone');
    $assert($users->findById($userA->id)?->timezone === 'Europe/Paris', 'Second user update leaves first user timezone unchanged');
} catch (Throwable $exception) {
    $failures[] = 'Unexpected test error: ' . $exception::class . ' (details withheld)';
} finally {
    foreach ([$userA, $userB] as $user) {
        if ($user instanceof User) {
            try {
                $cleanup = $pdo->prepare('DELETE FROM users WHERE id = :id');
                $cleanup->execute(['id' => $user->id]);
            } catch (Throwable) {
                $failures[] = 'Test user cleanup failed.';
            }
        }
    }
    if (is_int($registeredUserId)) {
        try {
            $cleanup = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $cleanup->execute(['id' => $registeredUserId]);
        } catch (Throwable) {
            $failures[] = 'Registered test user cleanup failed.';
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

foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
if ($failures !== []) {
    fwrite(STDERR, sprintf("Profile regression failed: %d assertions, %d failure(s).%s", $assertions, count($failures), PHP_EOL));
    exit(1);
}
fwrite(STDOUT, sprintf("Profile regression passed: %d assertions.%s", $assertions, PHP_EOL));
