<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $publicRoot = realpath(__DIR__);
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';
    $requestedFile = realpath(__DIR__ . DIRECTORY_SEPARATOR . ltrim($requestPath, '/\\'));

    if ($publicRoot !== false
        && $requestedFile !== false
        && str_starts_with($requestedFile, $publicRoot . DIRECTORY_SEPARATOR)
        && is_file($requestedFile)
        && strtolower(pathinfo($requestedFile, PATHINFO_EXTENSION)) !== 'php') {
        return false;
    }
}

use App\Core\Application;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$application = new Application();
$application->run();
