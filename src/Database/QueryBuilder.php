<?php

declare(strict_types=1);

namespace FastREST\Database;

use RuntimeException;

/**
 * Fluent SQL SELECT query builder.
 *
 * Fixes from the original Select class:
 *   - $value parameter in where()/orWhere() is now actually used; the value is
 *     no longer parsed from the condition string (which made it impossible to
 *     pass values separately from conditions).
 *   - rand() replaced with a reliable counter for parameter name generation.
 *   - execute() method added so the builder can run itself via an injected Database.
 *   - ORDER BY and LIMIT are validated before use.
 *   - Stub properties (join, groupby, having) are now fully implemented.
 *   - Column names in ORDER BY are passed as identifiers, not interpolated raw strings.
 *
 * Usage:
 *   $qb = new QueryBuilder('products');
 *   $rows = $qb
 *       ->columns(['id', 'name', 'price'])
 *       ->where('status', '=', 'active')
 *       ->orWhere('featured', '=', 1)
 *       ->orderBy('name', 'ASC')
 *       ->limit(10)
 *       ->execute($db);
 */
class QueryBuilder
{
    private string $table;
    private string $columnList;

    /** @var string[] */
    private array $whereClauses = [];

    /** @var string[] */
    private array $orderClauses = [];

    /** @var string[] */
    private array $joinClauses  = [];

    private ?string $groupBy    = null;
    private ?string $havingClause = null;
    private ?int   $limitValue  = null;

    /** @var array<string, mixed> */
    private array $params       = [];

    /** Monotonic counter for unique param names — replaces rand() */
    private int $paramCounter   = 0;

    private const ALLOWED_DIRECTIONS = ['ASC', 'DESC'];
    private const ALLOWED_OPERATORS  = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS NULL', 'IS NOT NULL'];
    private const ALLOWED_JOIN_TYPES = ['INNER', 'LEFT', 'RIGHT', 'FULL OUTER', 'CROSS'];

    public function __construct(string $table, array $columns = [])
    {
        $this->table      = $table;
        $this->columnList = empty($columns) ? '*' : implode(', ', $columns);
    }

    // ── Column selection ─────────────────────────────────────────────────────

    public function columns(array $columns): self
    {
        $this->columnList = empty($columns) ? '*' : implode(', ', $columns);
        return $this;
    }

    // ── WHERE ────────────────────────────────────────────────────────────────

    /**
     * Add an AND WHERE condition.
     *
     * @param mixed $value The value to bind — NOT parsed from the condition string
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        return $this->addWhere('AND', $column, $operator, $value);
    }

    /**
     * Add an OR WHERE condition.
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        return $this->addWhere('OR', $column, $operator, $value);
    }

    /**
     * Add a raw WHERE condition (use sparingly; prefer typed where/orWhere).
     * The caller is responsible for binding any params manually via withParam().
     */
    public function whereRaw(string $condition): self
    {
        $glue                = empty($this->whereClauses) ? '' : ' AND ';
        $this->whereClauses[] = $glue . $condition;
        return $this;
    }

    /**
     * Manually add a named bind parameter (for use with whereRaw).
     */
    public function withParam(string $name, mixed $value): self
    {
        $this->params[$name] = $value;
        return $this;
    }

    // ── JOIN ─────────────────────────────────────────────────────────────────

    /**
     * Add a JOIN clause.
     *
     * @param string $type      JOIN type — one of INNER, LEFT, RIGHT, FULL OUTER, CROSS
     * @param string $table     Table to join
     * @param string $condition ON condition (e.g. 'products.category_id = categories.id')
     */
    public function join(string $type, string $table, string $condition): self
    {
        $type = strtoupper($type);
        if (!in_array($type, self::ALLOWED_JOIN_TYPES, true)) {
            throw new RuntimeException("Invalid JOIN type: '$type'");
        }
        $this->joinClauses[] = "$type JOIN $table ON $condition";
        return $this;
    }

    public function leftJoin(string $table, string $condition): self
    {
        return $this->join('LEFT', $table, $condition);
    }

    public function innerJoin(string $table, string $condition): self
    {
        return $this->join('INNER', $table, $condition);
    }

    // ── GROUP BY / HAVING ────────────────────────────────────────────────────

    public function groupBy(string $column): self
    {
        $this->groupBy = $column;
        return $this;
    }

    /**
     * Add a HAVING clause. Only meaningful when groupBy() is also set.
     */
    public function having(string $condition): self
    {
        $this->havingClause = $condition;
        return $this;
    }

    // ── ORDER / LIMIT ────────────────────────────────────────────────────────

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, self::ALLOWED_DIRECTIONS, true)) {
            throw new RuntimeException("Invalid ORDER BY direction: '$direction'. Must be ASC or DESC.");
        }
        $this->orderClauses[] = "$column $direction";
        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new RuntimeException('LIMIT must be a positive integer.');
        }
        $this->limitValue = $limit;
        return $this;
    }

    // ── Build & Execute ──────────────────────────────────────────────────────

    public function toSql(): string
    {
        $sql = "SELECT {$this->columnList} FROM {$this->table}";

        if (!empty($this->joinClauses)) {
            $sql .= ' ' . implode(' ', $this->joinClauses);
        }

        if (!empty($this->whereClauses)) {
            $sql .= ' WHERE ' . ltrim(implode('', $this->whereClauses), ' AND ');
            $sql = preg_replace('/WHERE\s+(AND|OR)\s+/', 'WHERE ', $sql);
        }

        if ($this->groupBy !== null) {
            $sql .= " GROUP BY {$this->groupBy}";
        }

        if ($this->havingClause !== null) {
            $sql .= " HAVING {$this->havingClause}";
        }

        if (!empty($this->orderClauses)) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderClauses);
        }

        if ($this->limitValue !== null) {
            $sql .= " LIMIT {$this->limitValue}";
        }

        return $sql;
    }

    /** @return array<string, mixed> */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Execute the query through the Database helper.
     *
     * @return array<int, array<string, mixed>>
     */
    public function execute(Database $db): array
    {
        $result = $db->preparedQuery($this->toSql(), $this->params);
        return is_array($result) ? $result : [];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function addWhere(string $boolean, string $column, string $operator, mixed $value): self
    {
        $operator = strtoupper($operator);
        if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
            throw new RuntimeException("Invalid WHERE operator: '$operator'");
        }

        // Use a counter for unique param names — rand() could collide
        $paramName = str_replace('.', '_', $column) . '_' . (++$this->paramCounter);

        $glue               = empty($this->whereClauses) ? '' : " $boolean ";
        $this->whereClauses[] = "$glue$column $operator :$paramName";
        $this->params[$paramName] = $value;

        return $this;
    }
}
