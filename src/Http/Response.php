<?php

declare(strict_types=1);

namespace FastREST\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

/**
 * Convenience factory for JSON PSR-7 responses.
 *
 * Controllers should return a PSR-7 ResponseInterface. This class makes that
 * concise without coupling controllers to any specific PSR-7 implementation.
 *
 * Usage in a controller:
 *   return Response::json(['status' => 'ok', 'data' => $rows]);
 *   return Response::json(['message' => 'created'], 201);
 *   return Response::error('Validation failed', 422, ['field' => 'required']);
 */
class Response
{
    /**
     * Create a JSON response.
     *
     * @param array<mixed>             $data    Data to encode as JSON
     * @param int                      $status  HTTP status code (default 200)
     * @param array<string, string>    $headers Extra headers to include
     */
    public static function json(
        array $data,
        int $status = 200,
        array $headers = [],
    ): ResponseInterface {
        $factory = new Psr17Factory();
        $body    = $factory->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $response = $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->withBody($body);

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Create a structured error response.
     *
     * @param string               $message Human-readable error message
     * @param int                  $status  HTTP status code
     * @param array<string, mixed> $extra   Additional fields to merge into the body
     */
    public static function error(
        string $message,
        int $status = 400,
        array $extra = [],
    ): ResponseInterface {
        return self::json(array_merge([
            'status'  => 'error',
            'code'    => $status,
            'message' => $message,
        ], $extra), $status);
    }

    /**
     * Create a 204 No Content response (e.g. after a DELETE).
     */
    public static function noContent(): ResponseInterface
    {
        $factory = new Psr17Factory();
        return $factory->createResponse(204);
    }
}
