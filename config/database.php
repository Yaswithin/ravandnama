<?php

declare(strict_types=1);

use App\Core\Environment;

return [
    'host' => Environment::get('DB_HOST') ?: '',
    'port' => Environment::get('DB_PORT') ?: '',
    'database' => Environment::get('DB_DATABASE') ?: '',
    'username' => Environment::get('DB_USERNAME') ?: '',
    'password' => Environment::get('DB_PASSWORD') ?: '',
];
