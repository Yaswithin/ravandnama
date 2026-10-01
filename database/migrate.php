<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Environment;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
Environment::load($projectRoot . '/.env');

$migrationName = 'migration table setup';

try {
    $config = require $projectRoot . '/config/database.php';
    $pdo = (new Database($config))->connection();

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS migrations (
            migration VARCHAR(190) NOT NULL,
            executed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (migration)
        ) ENGINE=InnoDB
          DEFAULT CHARACTER SET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
        SQL);

    $files = glob($projectRoot . '/database/migrations/*.php') ?: [];
    sort($files, SORT_STRING);

    $isApplied = $pdo->prepare('SELECT 1 FROM migrations WHERE migration = :migration');
    $recordApplied = $pdo->prepare('INSERT INTO migrations (migration) VALUES (:migration)');
    $appliedCount = 0;

    foreach ($files as $file) {
        $migrationName = basename($file, '.php');
        $statements = require $file;

        if (!is_array($statements) || !array_is_list($statements)) {
            throw new \RuntimeException('Migration must return a list of SQL statements.');
        }

        $isApplied->execute(['migration' => $migrationName]);

        if ($isApplied->fetchColumn() !== false) {
            continue;
        }

        foreach ($statements as $statement) {
            if (!is_string($statement) || trim($statement) === '') {
                throw new \RuntimeException('Migration statements must be non-empty SQL strings.');
            }

            $pdo->exec($statement);
        }

        $recordApplied->execute(['migration' => $migrationName]);
        $appliedCount++;
        fwrite(STDOUT, "Applied migration: {$migrationName}\n");
    }

    if ($appliedCount === 0) {
        fwrite(STDOUT, "No pending migrations.\n");
    }
} catch (Throwable $exception) {
    $sqlState = $exception instanceof \PDOException && isset($exception->errorInfo[0])
        ? (string) $exception->errorInfo[0]
        : (string) $exception->getCode();

    error_log(sprintf(
        'Migration failed during %s (%s, SQLSTATE %s).',
        $migrationName,
        $exception::class,
        $sqlState,
    ));

    fwrite(STDERR, sprintf(
        "Migration failed during %s (SQLSTATE %s). Check the migration SQL and MySQL configuration.\n",
        $migrationName,
        $sqlState,
    ));
    exit(1);
}
