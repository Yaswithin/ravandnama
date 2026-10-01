<?php

declare(strict_types=1);

use App\Core\Request;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assertions = 0;
$failures = [];
$originalServer = $_SERVER;
$originalGet = $_GET;
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;

    if (!$condition) {
        $failures[] = $label;
    }
};

try {
    $normalizeHeaders = new ReflectionMethod(Request::class, 'normalizeHeaders');
    $normalizeHeaders->setAccessible(true);

    $sapiToken = str_repeat('a', 64);
    $sapiHeaders = $normalizeHeaders->invoke(null, ['X-CSRF-Token' => $sapiToken], []);
    $sapiRequest = new Request('POST', '/api/auth/register', headers: $sapiHeaders);
    $assert($sapiRequest->header('X-CSRF-Token') === $sapiToken, 'Header provided by getallheaders source is available');
    $assert($sapiRequest->header('x-csrf-token') === $sapiToken, 'Header lookup is case-insensitive');

    $precedenceHeaders = $normalizeHeaders->invoke(
        null,
        ['X-CSRF-Token' => 'sapi-value'],
        ['HTTP_X_CSRF_TOKEN' => 'server-value'],
    );
    $precedenceRequest = new Request('POST', '/api/auth/register', headers: $precedenceHeaders);
    $assert($precedenceRequest->header('X-CSRF-Token') === 'sapi-value', 'getallheaders value takes precedence over $_SERVER fallback');

    $duplicateHeaders = $normalizeHeaders->invoke(
        null,
        ['X-CSRF-Token' => 'first-sapi-value', 'x-csrf-token' => 'second-sapi-value'],
        ['HTTP_X_CSRF_TOKEN' => 'server-value'],
    );
    $duplicateRequest = new Request('POST', '/api/auth/register', headers: $duplicateHeaders);
    $assert($duplicateRequest->header('X-CSRF-Token') === 'first-sapi-value', 'First case-insensitive duplicate is retained');

    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/auth/register',
        'HTTP_X_CSRF_TOKEN' => str_repeat('b', 64),
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => '12',
    ];
    $_GET = [];
    $serverRequest = Request::fromGlobals();
    $assert($serverRequest->header('X-CSRF-Token') === str_repeat('b', 64), 'X-CSRF-Token is available from $_SERVER fallback');
    $assert($serverRequest->header('content-type') === 'application/json', 'CONTENT_TYPE is exposed as Content-Type');
    $assert($serverRequest->header('Content-Length') === '12', 'CONTENT_LENGTH is exposed as Content-Length');
    $assert($serverRequest->header('X-Missing-Header') === null, 'Missing header returns null');
} finally {
    $_SERVER = $originalServer;
    $_GET = $originalGet;
}

if ($failures !== []) {
    fwrite(STDERR, 'Request regression failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Request regression passed ({$assertions} assertions).\n");
