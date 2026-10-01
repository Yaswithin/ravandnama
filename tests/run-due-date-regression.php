<?php

declare(strict_types=1);

use App\Core\UtcInstant;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) $failures[] = $label;
};

$assert(
    UtcInstant::fromRfc3339('2026-09-30T18:30:00+03:30') === '2026-09-30 15:00:00.000000',
    'Explicit positive offset normalizes to UTC',
);
$assert(
    UtcInstant::fromRfc3339('2026-01-01T00:15:00+01:00') === '2025-12-31 23:15:00.000000',
    'Offset conversion can cross the Gregorian year boundary',
);
$assert(
    UtcInstant::fromRfc3339('2024-02-29T23:30:00-02:00') === '2024-03-01 01:30:00.000000',
    'Leap-day input can cross midnight into the next day',
);
$assert(
    UtcInstant::fromRfc3339('2026-09-30T18:30:00.125Z') === '2026-09-30 18:30:00.125000',
    'Millisecond precision is retained in DATETIME(6) storage',
);
$assert(
    UtcInstant::toRfc3339('2026-09-30 15:00:00.000000') === '2026-09-30T15:00:00Z',
    'Zero fractions are omitted in canonical response form',
);
$assert(
    UtcInstant::toRfc3339('2026-09-30 15:00:00.125000') === '2026-09-30T15:00:00.125Z',
    'Stored millisecond precision is retained in RFC3339 response',
);
$assert(UtcInstant::toRfc3339(null) === null, 'Null canonical due date remains null');

foreach ([
    '2026-09-30T18:30:00',
    '2024-02-30T18:30:00Z',
    '2026-09-30T25:30:00Z',
    '2026-09-30T18:30:00+24:00',
    '2026-09-30T18:30:00+03:99',
    '2026-09-30T18:30:00-00:00',
    '2026-09-30T18:30:00.1234Z',
    '2026-09-30T18:30:60Z',
    '0999-12-31T23:30:00+01:00',
    'not-a-date',
] as $invalid) {
    $assert(UtcInstant::fromRfc3339($invalid) === null, 'Reject invalid RFC3339 due date: ' . $invalid);
}

$invalidStoredValueRejected = false;
try {
    UtcInstant::toRfc3339('2026-02-30 15:00:00.000000');
} catch (UnexpectedValueException) {
    $invalidStoredValueRejected = true;
}
$assert($invalidStoredValueRejected, 'Malformed stored canonical values fail closed');

$migration = require dirname(__DIR__) . '/database/migrations/202609290001_add_due_at_utc_to_tasks_table.php';
$assert(count($migration) === 1, 'Migration contains one additive schema statement');
$assert(
    preg_match('/\A\s*ALTER\s+TABLE\s+tasks\s+ADD\s+COLUMN\s+due_at_utc\s+DATETIME\(6\)\s+NULL\s+AFTER\s+due_at\s*\z/i', $migration[0]) === 1,
    'Migration adds only nullable due_at_utc and leaves due_at untouched',
);
$assert(!preg_match('/\b(UPDATE|DROP|MODIFY|CHANGE)\b/i', implode("\n", $migration)), 'Migration does not rewrite or remove existing records/schema');

$schema = file_get_contents(dirname(__DIR__) . '/docs/deployment/database-schema.sql');
$assert(is_string($schema) && preg_match('/due_at\s+DATETIME\(6\)\s+NULL,\s*due_at_utc\s+DATETIME\(6\)\s+NULL/s', $schema) === 1, 'Fresh schema retains legacy column beside canonical column');

foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}" . PHP_EOL);
if ($failures !== []) {
    fwrite(STDERR, sprintf("Due Date regression failed: %d assertions, %d failure(s).%s", $assertions, count($failures), PHP_EOL));
    exit(1);
}

fwrite(STDOUT, sprintf("Due Date regression passed: %d assertions.%s", $assertions, PHP_EOL));
