<?php

declare(strict_types=1);

namespace FastREST\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Thin wrapper around a PDO connection.
 *
 * Constructed with named parameters so the DI container can build it from
 * environment variables without the class itself knowing about $_ENV.
 * Registered as a shared service in config/container.php so only one
 * connection is created per request.
 */
class Connection
{
    private PDO $pdo;

    public function __construct(
        string $type,
        string $host,
        int    $port,
        string $dbName,
        string $user,
        string $password,
        array  $options = [],
    ) {
        $dsn = match ($type) {
            'mysql'  => "mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
            'pgsql'  => "pgsql:host=$host;port=$port;dbname=$dbName",
            'sqlite' => "sqlite:$dbName",
            default  => throw new RuntimeException("Unsupported DB type: $type"),
        };

        $defaults = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // use native prepared statements
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $password, $options + $defaults);
        } catch (PDOException $e) {
            // Wrap so the DSN (which may contain credentials) is never exposed
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
