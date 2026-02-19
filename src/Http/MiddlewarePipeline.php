<?php

declare(strict_types=1);

namespace FastREST\Http;

use FastREST\Exceptions\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * PSR-15 middleware pipeline.
 *
 * Middlewares are processed in FIFO order. The final item in the list is
 * treated as the request handler (typically the Router).
 *
 * Any unhandled HttpException is converted into a proper JSON error response
 * with the correct HTTP status code, so application code never needs to
 * manually set headers for error conditions.
 *
 * Usage:
 *   $pipeline = new MiddlewarePipeline([
 *       $loggerMiddleware,
 *       $corsMiddleware,
 *       $router,          // must implement RequestHandlerInterface
 *   ]);
 *   $response = $pipeline->handle($request);
 */
class MiddlewarePipeline implements RequestHandlerInterface
{
    /** @param array<MiddlewareInterface|RequestHandlerInterface> $stages */
    public function __construct(private array $stages)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->next()->handle($request);
        } catch (HttpException $e) {
            return $this->httpExceptionResponse($e);
        }
    }

    private function next(): RequestHandlerInterface
    {
        $stage = array_shift($this->stages);

        if ($stage instanceof RequestHandlerInterface) {
            return $stage;
        }

        /** @var MiddlewareInterface $stage */
        $remaining = $this->stages;

        return new class($stage, new self($remaining)) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface    $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }

    private function httpExceptionResponse(HttpException $e): ResponseInterface
    {
        $factory  = new Psr17Factory();
        $body     = $factory->createStream(json_encode([
            'status'  => 'error',
            'code'    => $e->getStatusCode(),
            'message' => $e->getMessage(),
        ], JSON_THROW_ON_ERROR));

        $response = $factory->createResponse($e->getStatusCode())
            ->withBody($body)
            ->withHeader('Content-Type', 'application/json');

        foreach ($e->getHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
