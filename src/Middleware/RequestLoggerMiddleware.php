<?php

declare(strict_types=1);

namespace FastREST\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * PSR-15 middleware that logs every incoming request and outgoing response.
 *
 * Sanitizes user-supplied values before logging to prevent log injection
 * (strips newlines and carriage returns).
 */
class RequestLoggerMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = microtime(true);

        $this->logger->info('Request', [
            'method' => $request->getMethod(),
            'uri'    => $this->sanitize((string) $request->getUri()),
            'ip'     => $this->sanitize($this->resolveIp($request)),
        ]);

        $response = $handler->handle($request);

        $this->logger->info('Response', [
            'status'   => $response->getStatusCode(),
            'duration' => round((microtime(true) - $start) * 1000, 2) . 'ms',
        ]);

        return $response;
    }

    /** Strip characters commonly used in log injection attacks. */
    private function sanitize(string $value): string
    {
        return str_replace(["\n", "\r", "\t"], ['\\n', '\\r', '\\t'], $value);
    }

    private function resolveIp(ServerRequestInterface $request): string
    {
        // Prefer the real IP from a trusted proxy header if present
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            // X-Forwarded-For can be a comma-separated list; take the first
            return trim(explode(',', $forwarded)[0]);
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }
}
