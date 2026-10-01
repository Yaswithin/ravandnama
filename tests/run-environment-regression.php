<?php

declare(strict_types=1);

use App\Core\Environment;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$name = 'RAVANDNAMA_ENV_REGRESSION_' . strtoupper(bin2hex(random_bytes(6)));
$originalProcessValue = getenv($name);
$hadEnvironmentValue = array_key_exists($name, $_ENV);
$originalEnvironmentValue = $_ENV[$name] ?? null;
$hadRequestValue = array_key_exists($name, $_REQUEST);
$originalRequestValue = $_REQUEST[$name] ?? null;
$configurationNames = ['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'API_MAX_BODY_BYTES'];
$originalConfigurationValues = [];
foreach ($configurationNames as $configurationName) {
    $originalConfigurationValues[$configurationName] = [
        'process' => getenv($configurationName),
        'env_defined' => array_key_exists($configurationName, $_ENV),
        'env_value' => $_ENV[$configurationName] ?? null,
    ];
}
$assertions = 0;
$failures = [];
$errorHandlerActive = false;

$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;

    if (!$condition) {
        $failures[] = $label;
    }
};

try {
    putenv($name);
    unset($_ENV[$name], $_REQUEST[$name]);
    $_ENV[$name] = 'from-env-superglobal';
    $assert(Environment::get($name) === 'from-env-superglobal', 'Reads a loaded $_ENV value');

    unset($_ENV[$name]);
    putenv($name . '=from-process-environment');
    $assert(Environment::get($name) === 'from-process-environment', 'Reads a getenv value');

    $_ENV[$name] = 'lower-priority-value';
    $assert(Environment::get($name) === 'from-process-environment', 'Process environment takes precedence over $_ENV');

    putenv($name . '=');
    $assert(Environment::get($name, 'fallback') === '', 'An explicitly empty process value is preserved');

    putenv($name);
    $_ENV[$name] = '';
    $assert(Environment::get($name, 'fallback') === '', 'An explicitly empty $_ENV value is preserved');

    unset($_ENV[$name]);
    $_REQUEST[$name] = 'request-controlled-value';
    $assert(Environment::get($name) === null, 'Missing values do not come from request data');
    $assert(Environment::get($name, 'fallback') === 'fallback', 'Missing values use the supplied default');

    foreach ($configurationNames as $configurationName) {
        putenv($configurationName);
        unset($_ENV[$configurationName]);
    }
    $_ENV['DB_HOST'] = 'synthetic-host';
    $_ENV['DB_PORT'] = '3306';
    $_ENV['DB_DATABASE'] = 'synthetic-database';
    $_ENV['DB_USERNAME'] = 'synthetic-user';
    $_ENV['DB_PASSWORD'] = 'synthetic-password';
    $_ENV['API_MAX_BODY_BYTES'] = '12345';
    $databaseConfig = require dirname(__DIR__) . '/config/database.php';
    $appConfig = require dirname(__DIR__) . '/config/app.php';
    $assert($databaseConfig['host'] === 'synthetic-host'
        && $databaseConfig['database'] === 'synthetic-database'
        && $databaseConfig['username'] === 'synthetic-user'
        && $databaseConfig['password'] === 'synthetic-password', 'Database config reads $_ENV values');
    $assert($appConfig['max_request_body_bytes'] === 12345, 'Application config reads $_ENV values');

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $severity;
        return true;
    });
    $errorHandlerActive = true;
    ob_start();
    putenv($name . '=synthetic-secret-value');
    Environment::get($name);
    $output = (string) ob_get_clean();
    restore_error_handler();
    $errorHandlerActive = false;
    $assert($output === '' && $warnings === [], 'Configuration reads emit no values, output, or warnings');
} finally {
    if ($errorHandlerActive) {
        restore_error_handler();
    }
    if ($originalProcessValue === false) {
        putenv($name);
    } else {
        putenv($name . '=' . $originalProcessValue);
    }

    if ($hadEnvironmentValue) {
        $_ENV[$name] = $originalEnvironmentValue;
    } else {
        unset($_ENV[$name]);
    }

    if ($hadRequestValue) {
        $_REQUEST[$name] = $originalRequestValue;
    } else {
        unset($_REQUEST[$name]);
    }

    foreach ($originalConfigurationValues as $configurationName => $originalValues) {
        if ($originalValues['process'] === false) {
            putenv($configurationName);
        } else {
            putenv($configurationName . '=' . $originalValues['process']);
        }

        if ($originalValues['env_defined']) {
            $_ENV[$configurationName] = $originalValues['env_value'];
        } else {
            unset($_ENV[$configurationName]);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'Environment regression failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Environment regression passed ({$assertions} assertions).\n");
