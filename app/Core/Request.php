<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class Request
{
    public const DEFAULT_MAX_BODY_BYTES = 1048576;
    public const MAX_CONFIGURABLE_BODY_BYTES = 2097152;

    public readonly bool $bodyTooLarge;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES,
        ?bool $bodyTooLarge = null,
    ) {
        if ($maxBodyBytes < 1 || $maxBodyBytes >= PHP_INT_MAX) {
            throw new InvalidArgumentException('The request body limit must be a positive byte count.');
        }

        $this->bodyTooLarge = $bodyTooLarge ?? strlen($body) > $maxBodyBytes;
    }

    public static function fromGlobals(int $maxBodyBytes = self::DEFAULT_MAX_BODY_BYTES): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $headers = is_array($headers) ? $headers : [];
        $headers = self::normalizeHeaders($headers, $_SERVER);

        $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
        $bodyTooLarge = is_int($contentLength) && $contentLength > $maxBodyBytes;
        $body = '';

        if (!$bodyTooLarge) {
            $input = fopen('php://input', 'rb');

            if ($input !== false) {
                $readBody = stream_get_contents($input, $maxBodyBytes + 1);
                fclose($input);
                $body = is_string($readBody) ? $readBody : '';
                $bodyTooLarge = strlen($body) > $maxBodyBytes;
            }
        }

        return new self(
            $method,
            is_string($path) && $path !== '' ? $path : '/',
            $_GET,
            $headers,
            $body,
            $maxBodyBytes,
            $bodyTooLarge,
        );
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidArgumentException When the media type or JSON body is invalid.
     */
    public function json(): array
    {
        if ($this->bodyTooLarge) {
            throw new InvalidArgumentException('Request body exceeds the configured limit.');
        }

        $contentType = null;

        foreach ($this->headers as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                $contentType = trim(explode(';', (string) $value, 2)[0]);
                break;
            }
        }

        $contentType = strtolower((string) $contentType);
        $isJson = $contentType === 'application/json'
            || preg_match('/^application\/[a-z0-9!#$&^_.+-]+\+json$/', $contentType) === 1;

        if (!$isJson) {
            throw new InvalidArgumentException('Content-Type must identify a JSON media type.');
        }

        try {
            $decoded = json_decode($this->body, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Request body contains invalid JSON.', previous: $exception);
        }

        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('Request body must be a JSON object.');
        }

        return get_object_vars($decoded);
    }

    public function header(string $name): ?string
    {
        $normalizedName = strtolower($name);

        foreach ($this->headers as $headerName => $value) {
            if (strtolower((string) $headerName) === $normalizedName) {
                return is_string($value) ? $value : (string) $value;
            }
        }

        return null;
    }

    /**
     * Merge SAPI headers with CGI server variables. Existing SAPI headers have precedence.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers, array $server): array
    {
        $normalizedHeaders = [];

        foreach ($headers as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalizedName = self::normalizeHeaderName((string) $name);

            if ($normalizedName !== null && !array_key_exists($normalizedName, $normalizedHeaders)) {
                $normalizedHeaders[$normalizedName] = (string) $value;
            }
        }

        foreach ($server as $name => $value) {
            $serverName = strtoupper((string) $name);

            if (str_starts_with($serverName, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($serverName, 5));
            } elseif ($serverName === 'CONTENT_TYPE' || $serverName === 'CONTENT_LENGTH') {
                $headerName = str_replace('_', '-', $serverName);
            } else {
                continue;
            }

            $normalizedName = self::normalizeHeaderName($headerName);

            if (!is_scalar($value)
                || $normalizedName === null
                || array_key_exists($normalizedName, $normalizedHeaders)) {
                continue;
            }

            $normalizedHeaders[$normalizedName] = (string) $value;
        }

        return $normalizedHeaders;
    }

    private static function normalizeHeaderName(string $name): ?string
    {
        if ($name === '' || preg_match("/\\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\\z/D", $name) !== 1) {
            return null;
        }

        return strtolower($name);
    }

}
