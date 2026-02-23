# FastREST v2

> The fastest way to build PHP REST APIs — now PSR-compliant, secure, and production-ready.

FastREST started as a zero-learning-curve PHP framework: drop in a controller, name a method, get a route. No config, no boilerplate, no framework tax. That spirit is unchanged in v2.

---

## Requirements

- **PHP 8.4+** (Uses latest language features and optimizations)
- **Composer** (For dependency management)

What *is* changed is everything underneath. v2 is built on the PHP standard interfaces (PSR-3, 4, 7, 11, 15) so every component — the logger, the HTTP layer, the container — can be swapped for any compatible package without touching your business logic. The original bugs that caused silent data corruption are fixed. Routes are HTTP-verb-aware. Credentials live in `.env`. Controllers are testable.

---

## Table of Contents

- [Quick Start](#quick-start)
- [Core Concepts](#core-concepts)
- [Routing](#routing)
- [Controllers](#controllers)
- [Database](#database)
- [QueryBuilder](#querybuilder)
- [Middleware](#middleware)
- [Dependency Injection](#dependency-injection)
- [Logging](#logging)
- [HTTP Client](#http-client)
- [OAuth 1.0](#oauth-10)
- [HTTP Responses & Error Handling](#http-responses--error-handling)
- [Replacing / Swapping Modules](#replacing--swapping-modules)
- [Configuration](#configuration)
- [Testing](#testing)
- [Migrating from v1](#migrating-from-v1)
- [Project Structure](#project-structure)

---

## Installation

### 1. Using Composer (Recommended)
You can create a new project skeleton using the following command:

```bash
composer create-project fastrest/framework [project-name]
```

This will:
- Download the framework and dependencies.
- Copy `.env.example` to `.env`.
- Set up the project structure.

### 2. Manual Installation
```bash
git clone https://github.com/fastrest/framework.git [project-name]
cd [project-name]
composer install
```

---

## Quick Start

```bash
# 1. Set up environment
cp .env.example .env

# 2. Serve locally
php -S localhost:8000 public/index.php

# 3. Run tests
composer test
```

Point a production web server's document root at `/public`. Everything above `/public` is private.

**Nginx example:**
```nginx
root /var/www/fastrest/public;
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

---

## Core Concepts

FastREST v2 is built on five PHP standard interfaces. You never depend on a concrete implementation — only on the interface. This means every layer is replaceable.

| Standard | What it does | Default implementation |
|---|---|---|
| **PSR-4** | Autoloading | Composer |
| **PSR-3** | Logging | Monolog |
| **PSR-7** | HTTP request & response objects | Nyholm/PSR-7 |
| **PSR-11** | Dependency injection container | PHP-DI |
| **PSR-15** | Middleware pipeline | Custom (ships with framework) |

---

## Routing

Routes are defined explicitly in `config/routes.php`. Each route maps an HTTP verb and a URI pattern to a controller method.

```php
// config/routes.php
return static function (Router $router): void {

    $router->get('/products',          [ProductController::class, 'index']);
    $router->get('/products/{id}',     [ProductController::class, 'show']);
    $router->post('/products',         [ProductController::class, 'store']);
    $router->put('/products/{id}',     [ProductController::class, 'update']);
    $router->delete('/products/{id}',  [ProductController::class, 'destroy']);

};
```

### Route parameters

Segments wrapped in `{braces}` are captured and injected into the request as attributes:

```php
// Route: GET /products/{id}
public function show(ServerRequestInterface $request): ResponseInterface
{
    $id = $request->getAttribute('id'); // "42"
    ...
}
```

### Wrong method → 405

If a URI matches but the HTTP method doesn't, the framework automatically returns `405 Method Not Allowed` with an `Allow` header listing what's accepted. You don't write any of that code.

### Catch-all

```php
$router->any('/health', [HealthController::class, 'check']);
```

---

## Controllers

Controllers are plain PHP classes. There are no base classes to extend, no interfaces to implement. Dependencies go in the constructor — the DI container provides them automatically.

```php
<?php

namespace FastREST\Controllers;

use FastREST\Database\Database;
use FastREST\Http\Response;
use FastREST\Exceptions\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class ProductController
{
    public function __construct(
        private readonly Database        $db,
        private readonly LoggerInterface $logger,
    ) {}

    // GET /products
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $rows = $this->db->select('products');
        return Response::json(['status' => 'success', 'data' => $rows]);
    }

    // GET /products/{id}
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $id   = (int) $request->getAttribute('id');
        $rows = $this->db->select('products', [], ['id' => $id]);

        if (empty($rows)) {
            throw new HttpException(404, "Product $id not found.");
        }

        return Response::json(['status' => 'success', 'data' => $rows[0]]);
    }

    // POST /products
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody(); // JSON decoded by middleware

        $this->db->insert('products', [
            'name'  => $body['name'],
            'price' => $body['price'],
        ]);

        return Response::json(['status' => 'success'], 201);
    }
}
```

**Rules:**
- Each method receives a `ServerRequestInterface` and must return a `ResponseInterface`.
- Throw `HttpException` for any HTTP error — the pipeline converts it to a proper JSON response.
- No `static` methods needed. No singletons. No globals.

### Reading request data

```php
// Query string: GET /products?status=active
$status = $request->getQueryParams()['status'] ?? null;

// JSON body (parsed by JsonBodyParserMiddleware): POST /products
$body = (array) $request->getParsedBody();

// Route parameter: GET /products/{id}
$id = $request->getAttribute('id');

// Header
$token = $request->getHeaderLine('Authorization');
```

---

## Database

`Database` provides safe, parameterised CRUD. All column and table names are validated against an identifier regex. ORDER BY directions are whitelisted. No raw string interpolation anywhere.

### INSERT

```php
$db->insert('products', [
    'name'   => 'Widget',
    'price'  => 9.99,
    'status' => 'active',
]);
// Returns true on success, false on failure.
```

### SELECT

```php
// All rows
$rows = $db->select('products');

// With columns, WHERE, ORDER BY, LIMIT
$rows = $db->select(
    table:   'products',
    columns: ['id', 'name', 'price'],
    where:   ['status' => 'active'],
    order:   ['price' => 'DESC'],
    limit:   10,
);
// Returns array of associative arrays.
```

### UPDATE

```php
$db->update(
    table: 'products',
    data:  ['price' => 12.99, 'status' => 'sale'],
    where: ['id' => 5],
);
// Returns true/false.
```

### DELETE

```php
$db->delete('products', ['id' => 5]);
// Returns true/false.
```

### Parameterised raw query

Use this when you need something CRUD can't express. Values are always bound — never interpolated.

```php
$rows = $db->preparedQuery(
    'SELECT * FROM products WHERE price BETWEEN :min AND :max ORDER BY price ASC',
    ['min' => 5.0, 'max' => 50.0],
);
```

### Trusted raw query

For static, hardcoded SQL only — never pass user input here.

```php
$db->query('TRUNCATE TABLE sessions');
```

---

## QueryBuilder

The fluent `QueryBuilder` handles complex SELECTs: joins, group by, having, multiple where conditions.

```php
use FastREST\Database\QueryBuilder;

$rows = (new QueryBuilder('products', ['p.id', 'p.name', 'c.name AS category']))
    ->leftJoin('categories c', 'p.category_id = c.id')
    ->where('p.status', '=', 'active')
    ->orWhere('p.featured', '=', 1)
    ->where('p.price', '>', 0)
    ->groupBy('p.id')
    ->having('COUNT(p.id) > 0')
    ->orderBy('p.name', 'ASC')
    ->limit(20)
    ->execute($db);
```

### Available methods

| Method | Description |
|---|---|
| `where($col, $op, $value)` | AND WHERE condition |
| `orWhere($col, $op, $value)` | OR WHERE condition |
| `whereRaw($condition)` | Raw AND WHERE (no binding) |
| `withParam($name, $value)` | Bind a param for whereRaw |
| `leftJoin($table, $on)` | LEFT JOIN |
| `innerJoin($table, $on)` | INNER JOIN |
| `join($type, $table, $on)` | Any join type |
| `groupBy($column)` | GROUP BY |
| `having($condition)` | HAVING |
| `orderBy($column, $dir)` | ORDER BY (direction whitelisted) |
| `limit($n)` | LIMIT |
| `toSql()` | Get the SQL string |
| `getParams()` | Get the bound params |
| `execute($db)` | Run via Database and return rows |

**Allowed operators** for `where()`: `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=`, `LIKE`, `NOT LIKE`, `IN`, `NOT IN`, `IS NULL`, `IS NOT NULL`

---

## Middleware

Middleware wraps every request. Each piece of middleware can inspect or modify the request before passing it on, and inspect or modify the response on the way back.

### Built-in middleware

| Class | What it does |
|---|---|
| `RequestLoggerMiddleware` | Logs method, URI, IP, status, duration |
| `JsonBodyParserMiddleware` | Parses `application/json` bodies into `getParsedBody()` |
| `CorsMiddleware` | Adds CORS headers; handles OPTIONS preflight |

### Writing your own

Implement `Psr\Http\Server\MiddlewareInterface`:

```php
<?php

namespace App\Middleware;

use FastREST\Exceptions\HttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly TokenValidator $validator) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $request->getHeaderLine('Authorization');

        if (!$this->validator->isValid($token)) {
            throw new HttpException(401, 'Unauthorized');
        }

        // Optionally attach the authenticated user to the request
        $user    = $this->validator->getUser($token);
        $request = $request->withAttribute('user', $user);

        return $handler->handle($request);
    }
}
```

### Registering middleware

Order matters — middleware runs top to bottom on the request, bottom to top on the response.

```php
// public/index.php
$pipeline = new MiddlewarePipeline([
    $container->get(RequestLoggerMiddleware::class),  // 1st: log everything
    $container->get(CorsMiddleware::class),            // 2nd: CORS headers
    $container->get(AuthMiddleware::class),            // 3rd: authentication
    $container->get(JsonBodyParserMiddleware::class),  // 4th: parse body
    $router,                                           // last: dispatch
]);
```

---

## Dependency Injection

Services are wired in `config/container.php` using [PHP-DI](https://php-di.org/) definitions. The container resolves constructor dependencies automatically — you don't call `new` anywhere in your controllers.

### Binding a service

```php
// config/container.php
use function DI\create;
use function DI\factory;
use function DI\get;

return [

    // Factory: full control over construction
    MyService::class => factory(function (): MyService {
        return new MyService($_ENV['MY_API_KEY']);
    }),

    // Autowired: PHP-DI reads the constructor and injects automatically
    AnotherService::class => create(AnotherService::class)
        ->constructor(get(MyService::class)),

    // Interface binding: resolve SomeInterface to a concrete class
    SomeInterface::class => get(ConcreteImplementation::class),

];
```

### Using a service in a controller

Just type-hint it in the constructor. PHP-DI does the rest.

```php
class OrderController
{
    public function __construct(
        private readonly Database        $db,
        private readonly Mailer          $mailer,
        private readonly LoggerInterface $logger,
    ) {}
}
```

---

## Logging

The logger is a `Psr\Log\LoggerInterface` — any PSR-3-compatible logger works. Monolog ships as the default.

```php
// In any class that receives LoggerInterface via constructor injection:
$this->logger->debug('Cache miss', ['key' => $cacheKey]);
$this->logger->info('User logged in', ['user_id' => $id]);
$this->logger->warning('Slow query', ['duration_ms' => 1200]);
$this->logger->error('Payment failed', ['order_id' => $orderId, 'reason' => $msg]);
```

### Log levels

`debug` → `info` → `notice` → `warning` → `error` → `critical` → `alert` → `emergency`

### Configuration

Set in `.env`:

```env
LOG_CHANNEL=stderr         # or: file
LOG_LEVEL=debug            # minimum level to record
LOG_PATH=/var/log/app.log  # used when LOG_CHANNEL=file
```

---

## HTTP Client

`HttpClient` wraps cURL with proper error handling, all HTTP verbs, and SSL verification on by default.

```php
use FastREST\Helpers\HttpClient;

$client = new HttpClient('https://api.example.com', $logger);

// GET
$response = $client->get('/users', ['Authorization' => 'Bearer ' . $token]);

// POST with JSON body
$response = $client->post('/orders', ['product_id' => 5, 'qty' => 2]);

// PUT, PATCH, DELETE
$response = $client->put('/products/5', ['price' => 14.99]);
$response = $client->patch('/products/5', ['status' => 'sale']);
$response = $client->delete('/products/5');

// Reading the response
$response->statusCode;          // int, e.g. 200
$response->isSuccess();         // true for 2xx
$response->json();              // decoded body as array, or null
$response->body;                // raw string body
$response->getHeader('Content-Type');
```

If the cURL request fails (network error, timeout, etc.) an exception is thrown and logged — it is never silently swallowed.

---

## OAuth 1.0

Generate OAuth 1.0 `Authorization` headers for third-party API requests:

```php
use FastREST\Helpers\OauthHelper;

$headers = OauthHelper::buildAuthorizationHeaders(
    method:         'POST',
    url:            'https://api.example.com/oauth/request_token',
    consumerKey:    $_ENV['OAUTH_CONSUMER_KEY'],
    consumerSecret: $_ENV['OAUTH_CONSUMER_SECRET'],
    token:          $accessToken,        // optional
    tokenSecret:    $accessTokenSecret,  // optional
);

$client   = new HttpClient('https://api.example.com', $logger);
$response = $client->post('/resource', $body, $headers);
```

The nonce is generated with `random_bytes()` (cryptographically secure), not `str_shuffle`.

---

## HTTP Responses & Error Handling

### Returning responses from controllers

```php
use FastREST\Http\Response;

// 200 OK with JSON body
return Response::json(['status' => 'success', 'data' => $rows]);

// 201 Created
return Response::json(['status' => 'success', 'message' => 'Created.'], 201);

// 204 No Content (e.g. after DELETE)
return Response::noContent();

// Structured error
return Response::error('Validation failed', 422, ['field' => 'name is required']);
```

### Throwing HTTP errors

Throw `HttpException` from anywhere — controllers, middleware, services. The pipeline catches it and converts it to a JSON response with the correct status code.

```php
use FastREST\Exceptions\HttpException;

// 404
throw new HttpException(404, 'Product not found.');

// 405 with extra headers
throw new HttpException(405, 'Method Not Allowed', ['Allow' => 'GET, POST']);

// 500
throw new HttpException(500, 'Something went wrong.');
```

The JSON response shape is always:

```json
{
    "status": "error",
    "code": 404,
    "message": "Product not found."
}
```

---

## Replacing / Swapping Modules

Because every component is bound to a standard interface, swapping implementations is a one-file change in `config/container.php`. Your controllers and services never change.

### Swap the logger (PSR-3)

```php
// config/container.php — replace Monolog with any PSR-3 logger

use Psr\Log\LoggerInterface;
use Acme\MyCustomLogger;

return [
    LoggerInterface::class => factory(fn() => new MyCustomLogger()),
];
```

Any class that type-hints `LoggerInterface` will now receive `MyCustomLogger` automatically.

### Swap the DI container (PSR-11)

The framework only calls `$container->get(ClassName::class)` in the router. To replace PHP-DI:

1. Build your preferred PSR-11 container (e.g. [League Container](https://container.thephpleague.com/), [Symfony DI](https://symfony.com/doc/current/components/dependency_injection.html)).
2. Pass it to `new Router($yourContainer)` in `public/index.php`.

```php
// public/index.php
$container = (new YourContainerBuilder())->build();
$router    = new Router($container);
```

### Swap the PSR-7 HTTP library

The router, middleware, and controllers only type-hint PSR-7 interfaces. To switch from Nyholm to [Guzzle PSR-7](https://github.com/guzzle/psr7) or [Laminas Diactoros](https://docs.laminas.dev/laminas-diactoros/):

1. Replace `nyholm/psr7` and `nyholm/psr7-server` in `composer.json`.
2. Update the request factory section of `public/index.php` to use your library's `ServerRequestCreator` equivalent.
3. Nothing else changes.

### Swap the database layer

Bind a custom implementation to `Database::class` in `config/container.php`:

```php
Database::class => factory(function () {
    return new MyDoctrineAdapter(...); // your own wrapper
}),
```

### Add any PSR-15 middleware package

Any package implementing `Psr\Http\Server\MiddlewareInterface` works directly in the pipeline:

```bash
composer require middlewares/rate-limit
```

```php
// public/index.php
use Middlewares\RateLimit;

$pipeline = new MiddlewarePipeline([
    $container->get(RequestLoggerMiddleware::class),
    new RateLimit($limiter),        // ← drop it in
    $container->get(CorsMiddleware::class),
    $router,
]);
```

---

## Configuration

All configuration lives in `.env`. Copy `.env.example` to get started:

```env
# Application
APP_ENV=development     # development | production
APP_DEBUG=true
APP_TIMEZONE=UTC

# Database
DB_TYPE=mysql           # mysql | pgsql | sqlite
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=your_database
DB_USER=your_user
DB_PASS=your_password

# Logging
LOG_CHANNEL=stderr      # stderr | file
LOG_LEVEL=debug         # debug | info | warning | error
LOG_PATH=/var/log/fastrest/app.log
```

In production, set these as real environment variables (Apache `SetEnv`, Nginx `fastcgi_param`, Docker `ENV`, etc.) — `.env` files are a convenience for local development.

The `.env` file is in `.gitignore` and should never be committed.

---

## Testing

Tests use PHPUnit with an in-memory SQLite database — no real database required.

```bash
composer test
```

### Writing a test for your controller

```php
<?php

use FastREST\Database\Connection;
use FastREST\Database\Database;
use FastREST\Controllers\ProductController;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ProductControllerTest extends TestCase
{
    private ProductController $controller;

    protected function setUp(): void
    {
        $conn = new Connection('sqlite', '', 0, ':memory:', '', '');
        $db   = new Database($conn, new NullLogger());
        $db->query('CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, price REAL)');
        $db->insert('products', ['name' => 'Widget', 'price' => 9.99]);

        $this->controller = new ProductController($db, new NullLogger());
    }

    public function testIndexReturns200(): void
    {
        $factory  = new Psr17Factory();
        $request  = $factory->createServerRequest('GET', '/products');
        $response = $this->controller->index($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('success', $body['status']);
        $this->assertCount(1, $body['data']);
    }
}
```

Because controllers have no static state and all dependencies are injected, testing is straightforward — pass in a real in-memory DB and a `NullLogger`.

---

## Migrating from v1

### Controllers

| v1 | v2 |
|---|---|
| `static function indexAction()` | `public function index(ServerRequestInterface $request): ResponseInterface` |
| `return array('status' => 'success')` | `return Response::json(['status' => 'success'])` |
| `Database::getInstance()` | Constructor-injected `$this->db` |
| `Logger::getInstance()` | Constructor-injected `$this->logger` |
| Route auto-detected from class/method name | Registered explicitly in `config/routes.php` |

### Routes

v1 auto-detected routes from the URL → class name → method name. v2 routes are explicit. Add one line per route to `config/routes.php`. This is intentional — explicit routes are readable, secure, and debuggable.

### Config

| v1 | v2 |
|---|---|
| `config/config.php` with `define()` | `.env` file with `$_ENV` |
| Hard-coded in file (committed to git) | `.env` excluded from git |

---

## Project Structure

```
fastrest/
├── config/
│   ├── container.php        # Service wiring (PSR-11 definitions)
│   └── routes.php           # Route table
├── public/
│   └── index.php            # Entry point — only web-accessible directory
├── src/
│   ├── Controllers/         # Your application controllers
│   ├── Database/
│   │   ├── Connection.php   # PDO factory (env-aware)
│   │   ├── Database.php     # CRUD helper
│   │   └── QueryBuilder.php # Fluent SELECT builder
│   ├── Exceptions/
│   │   └── HttpException.php
│   ├── Helpers/
│   │   ├── HttpClient.php   # cURL wrapper (all methods, proper errors)
│   │   ├── HttpResponse.php # Response value object
│   │   └── OauthHelper.php  # OAuth 1.0 header generator
│   ├── Http/
│   │   ├── MiddlewarePipeline.php
│   │   ├── Response.php     # JSON response factory
│   │   └── Router.php       # HTTP-verb-aware dispatcher
│   └── Middleware/
│       ├── CorsMiddleware.php
│       ├── JsonBodyParserMiddleware.php
│       └── RequestLoggerMiddleware.php
├── tests/
│   └── Unit/
│       ├── Database/
│       │   ├── DatabaseTest.php
│       │   └── QueryBuilderTest.php
│       └── Http/
│           └── RouterTest.php
├── .env.example
├── .gitignore
├── composer.json
├── phpunit.xml
└── README.md
```

---

## License

MIT — see [LICENSE](LICENSE).
