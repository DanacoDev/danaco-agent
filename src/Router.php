<?php

class Router
{
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = rtrim($uri, '/') ?: '/';
        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if (strcasecmp($routeMethod, $method) !== 0) {
                continue;
            }
            if (!preg_match($pattern, $path, $matches)) {
                continue;
            }
            $args = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $args[$key] = $value;
                }
            }
            try {
                $handler($args);
            } catch (PDOException $e) {
                error_log($e->getMessage());
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => 'Database error.']);
                }
            } catch (Throwable $e) {
                error_log($e->getMessage());
                if (!headers_sent()) {
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => $e->getMessage()]);
                }
            }
            return;
        }
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Not found']);
    }
}
