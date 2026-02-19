<?php

declare(strict_types=1);

namespace FastREST\Helpers;

/**
 * Immutable value object representing an HTTP response from HttpClient.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int    $statusCode,
        public readonly string $body,
        /** @var array<string, string> */
        public readonly array  $headers = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** Decode the body as JSON and return the result, or null on failure. */
    public function json(): mixed
    {
        return json_decode($this->body, true);
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }
}
