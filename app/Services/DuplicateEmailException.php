<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class DuplicateEmailException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Email address is already registered.', previous: $previous);
    }
}
