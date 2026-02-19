<?php

declare(strict_types=1);

namespace FastREST\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use FastREST\Exceptions\HttpException;

/**
 * PSR-15 middleware that parses a JSON request body.
 *
 * When the Content-Type is application/json, the raw body is decoded and
 * stored as the request's parsed body, making it available to controllers
 * via $request->getParsedBody().
 *
 * Returns 400 Bad Request if the body is present but not valid JSON.
 */
class JsonBodyParserMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $rawBody = (string) $request->getBody();

            if ($rawBody !== '') {
                $decoded = json_decode($rawBody, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new HttpException(400, 'Invalid JSON body: ' . json_last_error_msg());
                }

                $request = $request->withParsedBody($decoded);
            }
        }

        return $handler->handle($request);
    }
}
