<?php

declare(strict_types=1);

namespace App\Core;

final class Environment
{
    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);

        if (is_string($value)) {
            return $value;
        }

        $value = $_ENV[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name === '' || self::get($name) !== null) {
                continue;
            }

            if (strlen($value) >= 2) {
                $quote = $value[0];

                if (($quote === '"' || $quote === "'") && $value[strlen($value) - 1] === $quote) {
                    $value = substr($value, 1, -1);
                }
            }

            if (function_exists('putenv')) {
                putenv($name . '=' . $value);
            }

            $_ENV[$name] = $value;
        }
    }
}
