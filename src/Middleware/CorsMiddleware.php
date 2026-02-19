<?php

declare(strict_types=1);

namespace FastREST\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * PSR-15 CORS middleware.
 *
 * Handles preflight OPTIONS requests and injects CORS headers into all
 * responses. Configure the allowed origins, methods, and headers in
 * config/container.php or via environment variables.
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $allowedOrigins = '*',
        private readonly string $allowedMethods = 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
        private readonly string $allowedHeaders = 'Content-Type, Authorization, X-Requested-With',
        private readonly int    $maxAge         = 3600,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Handle CORS preflight
        if ($request->getMethod() === 'OPTIONS') {
            $factory  = new Psr17Factory();
            $response = $factory->createResponse(204);
            return $this->addCorsHeaders($response);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response);
    }

    private function addCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin',  $this->allowedOrigins)
            ->withHeader('Access-Control-Allow-Methods', $this->allowedMethods)
            ->withHeader('Access-Control-Allow-Headers', $this->allowedHeaders)
            ->withHeader('Access-Control-Max-Age',       (string) $this->maxAge);
    }
}
