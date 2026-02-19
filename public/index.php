<?php

declare(strict_types=1);

/**
 * FastREST — Entry Point
 *
 * This is the only file that should be web-accessible.
 * Point your web server document root here.
 *
 * Nginx example:
 *   root /var/www/fastrest/public;
 *   location / { try_files $uri $uri/ /index.php?$query_string; }
 */

use DI\ContainerBuilder;
use FastREST\Http\MiddlewarePipeline;
use FastREST\Http\Router;
use FastREST\Middleware\CorsMiddleware;
use FastREST\Middleware\JsonBodyParserMiddleware;
use FastREST\Middleware\RequestLoggerMiddleware;
use Nyholm\Psr7Server\ServerRequestCreator;
use Nyholm\Psr7\Factory\Psr17Factory;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// ── 1. Load environment variables ────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad(); // safeLoad() doesn't throw if .env is missing

// ── 2. Build the PSR-11 DI container ────────────────────────────────────────
$builder = new ContainerBuilder();
$builder->addDefinitions(dirname(__DIR__) . '/config/container.php');

if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
    $builder->enableCompilation(dirname(__DIR__) . '/var/cache');
}

$container = $builder->build();

// ── 3. Build PSR-7 server request from globals ───────────────────────────────
$psr17Factory = new Psr17Factory();
$creator      = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request      = $creator->fromGlobals();

// ── 4. Register routes ───────────────────────────────────────────────────────
$router = new Router($container);
(require dirname(__DIR__) . '/config/routes.php')($router);

// ── 5. Build middleware pipeline and dispatch ────────────────────────────────
$pipeline = new MiddlewarePipeline([
    $container->get(RequestLoggerMiddleware::class),
    $container->get(CorsMiddleware::class),
    $container->get(JsonBodyParserMiddleware::class),
    $router,   // Router is the final PSR-15 handler
]);

$response = $pipeline->handle($request);

// ── 6. Emit the PSR-7 response ───────────────────────────────────────────────
http_response_code($response->getStatusCode());

foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}

echo $response->getBody();
