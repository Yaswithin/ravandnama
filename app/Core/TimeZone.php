<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeZone;

final class TimeZone
{
    /** @var list<string>|null */
    private static ?array $identifiers = null;

    /** @return list<string> */
    public static function identifiers(): array
    {
        return self::$identifiers ??= DateTimeZone::listIdentifiers(DateTimeZone::ALL);
    }

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, self::identifiers(), true);
    }
}
