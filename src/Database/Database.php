<?php

declare(strict_types=1);

namespace FastREST\Database;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Database helper with safe, parameterised CRUD operations.
 *
 * All fixes from the code review are applied:
 *   - bindValue() is used everywhere instead of bindParam() in loops
 *     (bindParam binds by reference; all params ended up as the last value).
 *   - The missing ':' prefix in update() bindParam is fixed.
 *   - ORDER BY direction and column names are whitelisted to prevent SQL injection.
 *   - query() no longer calls ->execute() after PDO::query() (double-execute bug).
 *   - Exceptions are caught and logged; callers get typed return values.
 *   - No more Singleton — injected via DI container.
 */
class Database
{
    private PDO $pdo;

    /** Allowed ORDER BY directions */
    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];

    public function __construct(
        Connection                      $connection,
        private readonly LoggerInterface $logger,
    ) {
        $this->pdo = $connection->getPdo();
    }

    // ── INSERT ───────────────────────────────────────────────────────────────

    /**
     * Insert a row into $table.
     *
     * @param array<string, mixed> $data Column => value pairs
     */
    public function insert(string $table, array $data): bool
    {
        $this->guardIdentifier($table);

        $columns   = array_keys($data);
        $values    = array_values($data);
        $colList   = implode(', ', array_map([$this, 'quoteIdentifier'], $columns));
        $paramList = implode(', ', array_map(static fn($c) => ':' . $c, $columns));

        $sql  = "INSERT INTO {$this->quoteIdentifier($table)} ($colList) VALUES ($paramList)";
        $stmt = $this->pdo->prepare($sql);

        // bindValue() — value is captured at bind time, not at execute time
        foreach ($values as $i => $value) {
            $stmt->bindValue(':' . $columns[$i], $value);
        }

        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error('Database::insert failed', ['error' => $e->getMessage(), 'table' => $table]);
            return false;
        }
    }

    // ── UPDATE ───────────────────────────────────────────────────────────────

    /**
     * Update rows in $table.
     *
     * @param array<string, mixed> $data  Columns to update
     * @param array<string, mixed> $where WHERE conditions (AND-joined)
     */
    public function update(string $table, array $data, array $where): bool
    {
        $this->guardIdentifier($table);
        $this->guardNotEmpty($data, 'data');
        $this->guardNotEmpty($where, 'where');

        // Build SET clause — use a unique prefix to avoid collisions with WHERE params
        $setClauses = [];
        foreach (array_keys($data) as $col) {
            $this->guardIdentifier($col);
            $setClauses[] = "{$this->quoteIdentifier($col)} = :set_$col";
        }

        // Build WHERE clause
        $whereClauses = [];
        foreach (array_keys($where) as $col) {
            $this->guardIdentifier($col);
            $whereClauses[] = "{$this->quoteIdentifier($col)} = :where_$col";
        }

        $sql  = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setClauses),
            implode(' AND ', $whereClauses),
        );
        $stmt = $this->pdo->prepare($sql);

        foreach ($data as $col => $value) {
            $stmt->bindValue(':set_' . $col, $value); // ← was missing ':' in original
        }
        foreach ($where as $col => $value) {
            $stmt->bindValue(':where_' . $col, $value);
        }

        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error('Database::update failed', ['error' => $e->getMessage(), 'table' => $table]);
            return false;
        }
    }

    // ── DELETE ───────────────────────────────────────────────────────────────

    /**
     * Delete rows from $table.
     *
     * @param array<string, mixed> $where WHERE conditions (AND-joined)
     */
    public function delete(string $table, array $where): bool
    {
        $this->guardIdentifier($table);
        $this->guardNotEmpty($where, 'where');

        $whereClauses = [];
        foreach (array_keys($where) as $col) {
            $this->guardIdentifier($col);
            $whereClauses[] = "{$this->quoteIdentifier($col)} = :$col";
        }

        $sql  = sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), implode(' AND ', $whereClauses));
        $stmt = $this->pdo->prepare($sql);

        foreach ($where as $col => $value) {
            $stmt->bindValue(':' . $col, $value);
        }

        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            $this->logger->error('Database::delete failed', ['error' => $e->getMessage(), 'table' => $table]);
            return false;
        }
    }

    // ── SELECT ───────────────────────────────────────────────────────────────

    /**
     * Select rows from $table.
     *
     * @param string[]             $columns Column names; empty means SELECT *
     * @param array<string, mixed> $where   WHERE conditions (AND-joined)
     * @param array<string, string>$order   ['column' => 'ASC|DESC']  — direction is whitelisted
     * @param int|null             $limit   Optional row limit
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(
        string $table,
        array  $columns = [],
        array  $where   = [],
        array  $order   = [],
        ?int   $limit   = null,
    ): array {
        $this->guardIdentifier($table);

        $colList = empty($columns)
            ? '*'
            : implode(', ', array_map([$this, 'quoteIdentifier'], $columns));

        $sql = "SELECT $colList FROM {$this->quoteIdentifier($table)}";

        if (!empty($where)) {
            $clauses = [];
            foreach (array_keys($where) as $col) {
                $this->guardIdentifier($col);
                $clauses[] = "{$this->quoteIdentifier($col)} = :$col";
            }
            $sql .= ' WHERE ' . implode(' AND ', $clauses);
        }

        if (!empty($order)) {
            $orderClauses = [];
            foreach ($order as $col => $dir) {
                $this->guardIdentifier($col);
                $dir = strtoupper($dir);
                if (!in_array($dir, self::ALLOWED_DIRECTIONS, true)) {
                    throw new RuntimeException("Invalid ORDER BY direction '$dir'. Must be ASC or DESC.");
                }
                $orderClauses[] = "{$this->quoteIdentifier($col)} $dir";
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderClauses);
        }

        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit; // integer cast — safe
        }

        $stmt = $this->pdo->prepare($sql);

        foreach ($where as $col => $value) {
            $stmt->bindValue(':' . $col, $value);
        }

        $stmt->execute();
        return $stmt->fetchAll();
    }

    // ── RAW QUERIES ──────────────────────────────────────────────────────────

    /**
     * Execute a parameterised query.
     * For SELECT, returns rows. For anything else, returns true on success.
     *
     * @param array<mixed> $params
     * @return array<int, array<string, mixed>>|bool
     */
    public function preparedQuery(string $sql, array $params = []): array|bool
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            // columnCount() > 0 means the statement returned a result set
            return $stmt->columnCount() > 0 ? $stmt->fetchAll() : true;
        } catch (PDOException $e) {
            $this->logger->error('Database::preparedQuery failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Execute a raw (non-parameterised) query.
     *
     * ⚠  Use only for trusted, static queries — NEVER interpolate user input here.
     *    Prefer preparedQuery() for anything involving external data.
     *
     * @return array<int, array<string, mixed>>|bool
     */
    public function query(string $sql): array|bool
    {
        try {
            // PDO::query() executes immediately and returns a PDOStatement.
            // Do NOT call ->execute() on the result (that was the original bug).
            $stmt = $this->pdo->query($sql);
            return $stmt->columnCount() > 0 ? $stmt->fetchAll() : true;
        } catch (PDOException $e) {
            $this->logger->error('Database::query failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Backtick-quote a MySQL/MariaDB identifier.
     * For PostgreSQL compatibility, switch to double-quotes.
     */
    private function quoteIdentifier(string $name): string
    {
        // Guard first, then quote
        $this->guardIdentifier($name);
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * Validate that an identifier contains only safe characters.
     * Prevents column/table name injection regardless of quoting.
     */
    private function guardIdentifier(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $name)) {
            throw new RuntimeException("Invalid SQL identifier: '$name'");
        }
    }

    private function guardNotEmpty(array $arr, string $argName): void
    {
        if (empty($arr)) {
            throw new RuntimeException("Database: '$argName' must not be empty.");
        }
    }
}
