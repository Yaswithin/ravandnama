<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;
use InvalidArgumentException;

final class Router
{
    /** @var array<string, array<string, array{regex: string, handler: callable}>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $path = $this->normalizePath($path);
        $segments = $path === '/' ? [] : explode('/', trim($path, '/'));
        $parameterNames = [];
        $patternSegments = [];

        foreach ($segments as $segment) {
            if (preg_match('/\A\{([A-Za-z][A-Za-z0-9_]*)\}\z/', $segment, $matches) === 1) {
                $name = $matches[1];

                if (in_array($name, $parameterNames, true)) {
                    throw new InvalidArgumentException('Route parameter names must be unique.');
                }

                $parameterNames[] = $name;
                $patternSegments[] = '(?P<' . $name . '>[^/]+)';
                continue;
            }

            $patternSegments[] = preg_quote($segment, '#');
        }

        $regex = '#^/' . implode('/', $patternSegments) . '$#D';
        $this->routes[$method][$path] = [
            'regex' => $regex,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): Response
    {
        $path = $this->normalizePath($request->path);
        $pathMatched = false;

        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (preg_match($route['regex'], $path, $matches) !== 1) {
                    continue;
                }

                $pathMatched = true;

                if ($method !== $request->method) {
                    continue;
                }

                $parameters = [];

                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $parameters[$key] = rawurldecode($value);
                    }
                }

                $handler = $route['handler'];
                $response = $parameters === []
                    ? $handler($request)
                    : $handler($request, $parameters);

                if (!$response instanceof Response) {
                    throw new LogicException('Route handlers must return a Response instance.');
                }

                return $response;
            }
        }

        if ($pathMatched) {
            return Response::json([
                'success' => false,
                'message' => 'Method not allowed',
            ], 405);
        }

        return Response::json([
            'success' => false,
            'message' => 'Route not found',
        ], 404);
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');

        return $normalized === '/' ? '/' : $normalized;
    }
}
