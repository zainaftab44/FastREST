<?php

declare(strict_types=1);

use FastREST\Database\Connection;
use FastREST\Database\Database;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

use function DI\create;
use function DI\factory;
use function DI\get;

/**
 * PHP-DI container definitions.
 *
 * Add your own service bindings here. Every entry here is lazily resolved —
 * nothing is instantiated until it's first requested.
 */
return [

    // -------------------------------------------------------------------------
    // Logger  (PSR-3 compliant via Monolog)
    // -------------------------------------------------------------------------
    LoggerInterface::class => factory(function (): LoggerInterface {
        $channel = $_ENV['LOG_CHANNEL'] ?? 'stderr';
        $level   = \Monolog\Level::fromName($_ENV['LOG_LEVEL'] ?? 'debug');

        $stream = match ($channel) {
            'file'  => $_ENV['LOG_PATH'] ?? '/tmp/fastrest.log',
            default => 'php://stderr',
        };

        $logger = new Logger('fastrest');
        $logger->pushHandler(new StreamHandler($stream, $level));

        return $logger;
    }),

    // -------------------------------------------------------------------------
    // Database connection (PDO wrapped)
    // -------------------------------------------------------------------------
    Connection::class => factory(function (): Connection {
        return new Connection(
            type:     $_ENV['DB_TYPE']   ?? 'mysql',
            host:     $_ENV['DB_HOST']   ?? '127.0.0.1',
            port:     (int) ($_ENV['DB_PORT']   ?? 3306),
            dbName:   $_ENV['DB_NAME']   ?? '',
            user:     $_ENV['DB_USER']   ?? '',
            password: $_ENV['DB_PASS']   ?? '',
        );
    }),

    Database::class => create(Database::class)
        ->constructor(get(Connection::class), get(LoggerInterface::class)),

];
