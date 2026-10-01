<?php

declare(strict_types=1);

use App\Core\TimeZone;
use App\Core\Database;
use App\Core\Session;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\ValidationException;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $label;
};

$assert(User::DEFAULT_TIMEZONE === 'Asia/Tehran', 'Application timezone default is Asia/Tehran');
$assert(TimeZone::isValid('Asia/Tehran'), 'Asia/Tehran is accepted');
$assert(TimeZone::isValid('Europe/Paris'), 'A valid IANA region identifier is accepted');
$assert(TimeZone::isValid('UTC'), 'UTC is accepted');
$assert(!TimeZone::isValid('Not/A_Timezone'), 'Unknown timezone identifier is rejected');
$assert(!TimeZone::isValid('UTC+03:30'), 'Arbitrary fixed-offset string is rejected');
$assert(!TimeZone::isValid(''), 'Empty timezone is rejected');

$user = User::fromRecord(['id' => 7, 'name' => 'Test', 'email' => 'test@example.invalid', 'timezone' => 'Europe/Paris']);
$assert($user->timezone === 'Europe/Paris' && $user->toArray()['timezone'] === 'Europe/Paris', 'User model reads and serializes the saved timezone');
$service = new AuthService(new UserRepository(new Database([
    'host' => 'unused', 'port' => '3306', 'database' => 'unused', 'username' => 'unused', 'password' => '',
])), new Session());
foreach ([null, 42, 'Not/A_Timezone', 'UTC+03:30'] as $invalidTimezone) {
    try {
        $service->updateTimezoneForUser(7, ['timezone' => $invalidTimezone]);
        $assert(false, 'Invalid timezone input is rejected before persistence');
    } catch (ValidationException $exception) {
        $assert(isset($exception->errors()['timezone']), 'Invalid timezone input uses normal field validation');
    }
}
try {
    $service->updateTimezoneForUser(7, ['timezone' => 'Europe/Paris', 'user_id' => 8]);
    $assert(false, 'User ID override is rejected');
} catch (ValidationException $exception) {
    $assert(isset($exception->errors()['user_id']), 'User ID override is rejected through validation');
}

$migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/202609260006_add_timezone_to_users_table.php');
$assert(str_contains($migration, 'ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT \'Asia/Tehran\''), 'Migration adds the defaulted timezone column');

if ($failures !== []) {
    fwrite(STDERR, 'Timezone regression failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Timezone regression passed ({$assertions} assertions).\n");
