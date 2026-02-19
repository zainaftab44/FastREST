<?php

declare(strict_types=1);

namespace FastREST\Tests\Unit\Http;

use FastREST\Exceptions\HttpException;
use FastREST\Http\Response;
use FastREST\Http\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        // Minimal container stub that returns the stub controller
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new class {
                    public function index(ServerRequestInterface $request): ResponseInterface
                    {
                        return Response::json(['status' => 'ok']);
                    }

                    public function show(ServerRequestInterface $request): ResponseInterface
                    {
                        $id = $request->getAttribute('id');
                        return Response::json(['id' => $id]);
                    }
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $this->router = new Router($container);
        $this->router->get('/products', ['StubController', 'index']);
        $this->router->get('/products/{id}', ['StubController', 'show']);
        $this->router->post('/products', ['StubController', 'index']);
    }

    private function makeRequest(string $method, string $uri): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        return $factory->createServerRequest($method, $uri);
    }

    public function testMatchesGetRoute(): void
    {
        $request  = $this->makeRequest('GET', '/products');
        $response = $this->router->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMatchesPostRoute(): void
    {
        $request  = $this->makeRequest('POST', '/products');
        $response = $this->router->handle($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testExtractsRouteParameter(): void
    {
        $request  = $this->makeRequest('GET', '/products/42');
        $response = $this->router->handle($request);
        $body     = json_decode((string) $response->getBody(), true);
        $this->assertSame('42', $body['id']);
    }

    public function testReturns404ForUnknownRoute(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $this->router->handle($this->makeRequest('GET', '/nonexistent'));
    }

    public function testReturns405ForWrongMethod(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(405);
        $this->router->handle($this->makeRequest('DELETE', '/products'));
    }
}
