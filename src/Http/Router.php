<?php

declare(strict_types=1);

namespace FastREST\Http;

use FastREST\Exceptions\HttpException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP-verb-aware router.
 *
 * - Routes are registered via get(), post(), put(), patch(), delete(), any().
 * - URI segments wrapped in {braces} are extracted and added to the Request
 *   as attributes, then forwarded to the controller method.
 * - Controllers are resolved through the PSR-11 container, making them fully
 *   injectable and testable.
 * - Returns proper 404 / 405 PSR-7 responses.
 */
class Router implements RequestHandlerInterface
{
    /** @var array<string, array<string, array{class: string, method: string}>> */
    private array $routes = [];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    // ── Registration helpers ─────────────────────────────────────────────────

    public function get(string $uri, array $handler): self
    {
        return $this->addRoute('GET', $uri, $handler);
    }

    public function post(string $uri, array $handler): self
    {
        return $this->addRoute('POST', $uri, $handler);
    }

    public function put(string $uri, array $handler): self
    {
        return $this->addRoute('PUT', $uri, $handler);
    }

    public function patch(string $uri, array $handler): self
    {
        return $this->addRoute('PATCH', $uri, $handler);
    }

    public function delete(string $uri, array $handler): self
    {
        return $this->addRoute('DELETE', $uri, $handler);
    }

    /** Register the same handler for every HTTP verb. */
    public function any(string $uri, array $handler): self
    {
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->addRoute($method, $uri, $handler);
        }
        return $this;
    }

    // ── PSR-15 handler ───────────────────────────────────────────────────────

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path   = '/' . ltrim(strtok($request->getUri()->getPath(), '?'), '/');

        $matchedAnyPath = false;

        foreach ($this->routes as $routeUri => $methodMap) {
            $params = $this->matchUri($routeUri, $path);

            if ($params === null) {
                continue;
            }

            $matchedAnyPath = true;

            if (!isset($methodMap[$method])) {
                $allowed = implode(', ', array_keys($methodMap));
                throw new HttpException(405, "Method Not Allowed. Allowed: $allowed", [
                    'Allow' => $allowed,
                ]);
            }

            // Inject route parameters as request attributes
            foreach ($params as $key => $value) {
                $request = $request->withAttribute($key, $value);
            }

            ['class' => $class, 'method' => $action] = $methodMap[$method];

            // Resolve through DI container so controllers get injected deps
            $controller = $this->container->get($class);

            return $controller->$action($request);
        }

        throw new HttpException(404, 'Not Found');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function addRoute(string $method, string $uri, array $handler): self
    {
        [$class, $action] = $handler;
        $this->routes['/'.ltrim($uri, '/')][$method] = [
            'class'  => $class,
            'method' => $action,
        ];
        return $this;
    }

    /**
     * Match a route URI pattern against a request path.
     *
     * Returns an associative array of captured parameters on match,
     * an empty array when the route matches with no params,
     * or null when the path does not match the pattern.
     */
    private function matchUri(string $routeUri, string $requestPath): ?array
    {
        // Convert {param} placeholders to named regex groups
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $routeUri);
        $pattern = '#^' . $pattern . '$#';

        if (!preg_match($pattern, $requestPath, $matches)) {
            return null;
        }

        // Return only named captures (filter out numeric keys)
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
