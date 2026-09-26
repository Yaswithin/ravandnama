<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class InvalidCredentialsException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Invalid email or password.');
    }
}
