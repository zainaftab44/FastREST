<?php

declare(strict_types=1);

namespace FastREST\Tests\Unit\Database;

use FastREST\Database\Connection;
use FastREST\Database\Database;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Unit tests for Database using an in-memory SQLite connection.
 * No mocking needed — SQLite lets us test real SQL behaviour.
 */
class DatabaseTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        // SQLite in-memory via the Connection class
        $connection = new Connection('sqlite', '', 0, ':memory:', '', '');
        $this->db   = new Database($connection, new NullLogger());

        // Create a test table
        $this->db->query('CREATE TABLE products (
            id     INTEGER PRIMARY KEY AUTOINCREMENT,
            name   TEXT NOT NULL,
            price  REAL NOT NULL,
            status TEXT DEFAULT "active"
        )');
    }

    // ── INSERT ───────────────────────────────────────────────────────────────

    public function testInsertReturnsTrue(): void
    {
        $result = $this->db->insert('products', ['name' => 'Widget', 'price' => 9.99]);
        $this->assertTrue($result);
    }

    public function testInsertedRowIsRetrievable(): void
    {
        $this->db->insert('products', ['name' => 'Gadget', 'price' => 19.99]);
        $rows = $this->db->select('products', [], ['name' => 'Gadget']);
        $this->assertCount(1, $rows);
        $this->assertSame('Gadget', $rows[0]['name']);
    }

    // ── SELECT ───────────────────────────────────────────────────────────────

    public function testSelectWithOrderBy(): void
    {
        $this->db->insert('products', ['name' => 'Zebra', 'price' => 5.0]);
        $this->db->insert('products', ['name' => 'Apple', 'price' => 2.0]);

        $rows = $this->db->select('products', ['name'], [], ['name' => 'ASC']);
        $this->assertSame('Apple', $rows[0]['name']);
        $this->assertSame('Zebra', $rows[1]['name']);
    }

    public function testSelectInvalidDirectionThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/direction/i');
        $this->db->select('products', [], [], ['name' => 'SIDEWAYS']);
    }

    public function testSelectWithLimit(): void
    {
        $this->db->insert('products', ['name' => 'A', 'price' => 1.0]);
        $this->db->insert('products', ['name' => 'B', 'price' => 2.0]);
        $this->db->insert('products', ['name' => 'C', 'price' => 3.0]);

        $rows = $this->db->select('products', [], [], [], 2);
        $this->assertCount(2, $rows);
    }

    // ── UPDATE ───────────────────────────────────────────────────────────────

    public function testUpdateChangesValue(): void
    {
        $this->db->insert('products', ['name' => 'OldName', 'price' => 1.0]);

        $this->db->update('products', ['name' => 'NewName'], ['name' => 'OldName']);

        $rows = $this->db->select('products', [], ['name' => 'NewName']);
        $this->assertCount(1, $rows);
    }

    /**
     * Critical regression test: all updated columns must get correct values.
     * The original bug used bindParam() in a loop — every param ended up with
     * the last value assigned. This test would have caught that bug.
     */
    public function testUpdateSetsAllColumnsCorrectly(): void
    {
        $this->db->insert('products', ['name' => 'Foo', 'price' => 1.0]);

        $this->db->update(
            'products',
            ['name' => 'Bar', 'price' => 99.99],
            ['name' => 'Foo'],
        );

        $rows = $this->db->select('products', [], ['name' => 'Bar']);
        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(99.99, (float) $rows[0]['price'], 0.001);
    }

    // ── DELETE ───────────────────────────────────────────────────────────────

    public function testDeleteRemovesRow(): void
    {
        $this->db->insert('products', ['name' => 'ToDelete', 'price' => 0.0]);
        $this->db->delete('products', ['name' => 'ToDelete']);
        $rows = $this->db->select('products', [], ['name' => 'ToDelete']);
        $this->assertCount(0, $rows);
    }

    // ── SAFETY ───────────────────────────────────────────────────────────────

    public function testInvalidIdentifierThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->select('products; DROP TABLE products--');
    }

    public function testEmptyWhereInDeleteThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->db->delete('products', []);
    }
}
