<?php

declare(strict_types=1);

namespace FastREST\Helpers;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lightweight HTTP client built on cURL.
 *
 * Fixes from the original Curl class:
 *   - curl_exec() errors are checked via curl_errno() / curl_error() —
 *     PHP exceptions are NOT thrown by cURL; the original try/catch did nothing.
 *   - All HTTP methods are supported (GET, POST, PUT, PATCH, DELETE).
 *   - Errors are logged and surfaced rather than swallowed silently.
 *   - SSL verification is enabled by default (was missing in original).
 *   - Response is returned as a typed HttpResponse value object.
 *
 * Usage:
 *   $client   = new HttpClient('https://api.example.com', $logger);
 *   $response = $client->get('/users', ['Authorization' => 'Bearer token']);
 *   $response = $client->post('/users', ['name' => 'Alice'], ['Content-Type' => 'application/json']);
 */
class HttpClient
{
    public function __construct(
        private readonly string          $baseUrl,
        private readonly LoggerInterface $logger,
        private readonly bool            $verifySsl = true,
    ) {}

    // ── Public API ───────────────────────────────────────────────────────────

    public function get(string $path = '', array $headers = []): HttpResponse
    {
        return $this->request('GET', $path, $headers);
    }

    public function post(string $path = '', array $body = [], array $headers = []): HttpResponse
    {
        return $this->request('POST', $path, $headers, $body);
    }

    public function put(string $path = '', array $body = [], array $headers = []): HttpResponse
    {
        return $this->request('PUT', $path, $headers, $body);
    }

    public function patch(string $path = '', array $body = [], array $headers = []): HttpResponse
    {
        return $this->request('PATCH', $path, $headers, $body);
    }

    public function delete(string $path = '', array $headers = []): HttpResponse
    {
        return $this->request('DELETE', $path, $headers);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $headers = [], array $body = []): HttpResponse
    {
        $url  = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $curl = curl_init();

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HEADER         => true, // include response headers in output
        ];

        if (!empty($body)) {
            $encoded = json_encode($body, JSON_THROW_ON_ERROR);
            $options[CURLOPT_POSTFIELDS] = $encoded;

            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($encoded);
        }

        if (!empty($headers)) {
            $options[CURLOPT_HTTPHEADER] = $this->normalizeHeaders($headers);
        }

        curl_setopt_array($curl, $options);

        $raw    = curl_exec($curl);
        $errno  = curl_errno($curl);
        $error  = curl_error($curl);
        $info   = curl_getinfo($curl);
        curl_close($curl);

        if ($errno !== 0 || $raw === false) {
            $this->logger->error('HttpClient request failed', [
                'url'   => $url,
                'errno' => $errno,
                'error' => $error,
            ]);
            throw new RuntimeException("cURL request to $url failed: $error (errno $errno)");
        }

        // Separate headers from body using the header size from curl_getinfo
        $headerSize = $info['header_size'];
        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody    = substr($raw, $headerSize);

        return new HttpResponse(
            statusCode:  (int) $info['http_code'],
            body:        $rawBody,
            headers:     $this->parseResponseHeaders($rawHeaders),
        );
    }

    /**
     * Normalize headers — accepts both ['Key: Value'] and ['Key' => 'Value'] forms.
     *
     * @param array<int|string, string> $headers
     * @return string[]
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[] = is_string($key) ? "$key: $value" : $value;
        }
        return $normalized;
    }

    /** @return array<string, string> */
    private function parseResponseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (str_contains($line, ':')) {
                [$key, $val]       = explode(':', $line, 2);
                $headers[trim($key)] = trim($val);
            }
        }
        return $headers;
    }
}
