<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use UnexpectedValueException;

final class UtcInstant
{
    private const RFC3339_PATTERN = '/\A(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,3}))?(Z|[+-]\d{2}:\d{2})\z/D';

    /** Convert a timezone-explicit RFC3339 instant to UTC DATETIME(6) storage form. */
    public static function fromRfc3339(string $value): ?string
    {
        if (preg_match(self::RFC3339_PATTERN, $value, $matches) !== 1 || $matches[3] === '-00:00') {
            return null;
        }

        $offset = $matches[3];
        if ($offset !== 'Z') {
            $offsetHours = (int) substr($offset, 1, 2);
            $offsetMinutes = (int) substr($offset, 4, 2);
            if ($offsetHours > 23 || $offsetMinutes > 59) {
                return null;
            }
            $offset = substr($offset, 0, 1) . substr($offset, 1, 5);
        } else {
            $offset = '+00:00';
        }

        $fraction = str_pad($matches[2] ?? '', 6, '0');
        $parseValue = $matches[1] . '.' . $fraction . $offset;
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            $parseValue,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s.uP') !== $parseValue) {
            return null;
        }

        $utc = $date->setTimezone(new DateTimeZone('UTC'));
        if ((int) $utc->format('Y') < 1000 || (int) $utc->format('Y') > 9999) {
            return null;
        }

        return $utc->format('Y-m-d H:i:s.u');
    }

    /** Convert the application's UTC-convention DATETIME(6) value to RFC3339. */
    public static function toRfc3339(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/\A(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?\z/D', $value, $matches) !== 1) {
            throw new UnexpectedValueException('Stored UTC due date is invalid.');
        }

        $fraction = str_pad($matches[2] ?? '', 6, '0');
        $parseValue = $matches[1] . '.' . $fraction;
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            $parseValue,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d H:i:s.u') !== $parseValue) {
            throw new UnexpectedValueException('Stored UTC due date is invalid.');
        }

        $fraction = rtrim($date->format('u'), '0');
        $instant = $date->format('Y-m-d\TH:i:s');

        return $instant . ($fraction === '' ? '' : '.' . $fraction) . 'Z';
    }
}
