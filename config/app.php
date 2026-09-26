<?php

declare(strict_types=1);

use App\Core\Request;

$configuredLimit = filter_var(getenv('API_MAX_BODY_BYTES'), FILTER_VALIDATE_INT);

if (!is_int($configuredLimit)
    || $configuredLimit < 1
    || $configuredLimit > Request::MAX_CONFIGURABLE_BODY_BYTES) {
    $configuredLimit = Request::DEFAULT_MAX_BODY_BYTES;
}

return [
    'max_request_body_bytes' => $configuredLimit,
];
