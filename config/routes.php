<?php

declare(strict_types=1);

use FastREST\Http\Router;
use FastREST\Controllers\ProductController;

/**
 * Route definitions.
 *
 * Format: $router->METHOD('uri', [ControllerClass::class, 'methodName']);
 *
 * URI segments wrapped in {braces} are captured as named route parameters and
 * injected into the controller method via the Request attribute bag.
 */
return static function (Router $router): void {

    // Products
    $router->get('/products',              [ProductController::class, 'index']);
    $router->get('/products/{id}',         [ProductController::class, 'show']);
    $router->post('/products',             [ProductController::class, 'store']);
    $router->put('/products/{id}',         [ProductController::class, 'update']);
    $router->delete('/products/{id}',      [ProductController::class, 'destroy']);

};
