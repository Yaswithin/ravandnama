<?php

declare(strict_types=1);

namespace App\Repositories;

use RuntimeException;

final class DuplicateEmailException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('A user with this email already exists.', previous: $previous);
    }
}
