<?php

declare(strict_types=1);

namespace App\Core;

use LogicException;

final class Router
{
    /** @var array<string, array<string, callable(Request): Response>> */
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
        $this->routes[$path][$method] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $path = $this->normalizePath($request->path);
        $handler = $this->routes[$path][$request->method] ?? null;

        if ($handler === null) {
            if (isset($this->routes[$path])) {
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

        $response = $handler($request);

        if (!$response instanceof Response) {
            throw new LogicException('Route handlers must return a Response instance.');
        }

        return $response;
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . trim($path, '/');

        return $normalized === '/' ? '/' : $normalized;
    }
}
