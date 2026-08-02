<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimal REST router with route params.
 */
final class Router
{
    /** @var array<string, list<array{pattern:string, handler:callable, params:list<string>}>> */
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

    private function add(string $method, string $path, callable $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);
        $this->routes[$method][] = [
            'pattern' => '#^' . $regex . '$#',
            'handler' => $handler,
            'params'  => $params,
        ];
    }

    public function dispatch(string $method, string $path): mixed
    {
        $method = strtoupper($method);
        $routes = $this->routes[$method] ?? [];

        foreach ($routes as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                array_shift($matches);
                $args = array_combine($route['params'], $matches) ?: [];
                return ($route['handler'])($args);
            }
        }

        // Method not allowed?
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $m) {
            if ($m === $method) {
                continue;
            }
            foreach ($this->routes[$m] ?? [] as $route) {
                if (preg_match($route['pattern'], $path)) {
                    Response::error('Method not allowed.', 405);
                }
            }
        }

        Response::notFound('Endpoint not found: ' . $path);
    }
}
