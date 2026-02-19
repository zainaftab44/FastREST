<?php

declare(strict_types=1);

namespace FastREST\Exceptions;

use RuntimeException;

/**
 * Represents an HTTP-level error that should be converted into an error
 * response by the middleware pipeline.
 *
 * Throw this from controllers or middleware to produce clean JSON error
 * responses with the correct HTTP status code — without touching headers
 * manually.
 *
 * Usage:
 *   throw new HttpException(404, 'Product not found');
 *   throw new HttpException(405, 'Method Not Allowed', ['Allow' => 'GET, POST']);
 */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly int   $statusCode,
        string                 $message = '',
        private readonly array $headers  = [],
    ) {
        parent::__construct($message, $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
