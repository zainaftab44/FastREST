<?php

declare(strict_types=1);

namespace FastREST\Tests\Unit\Database;

use FastREST\Database\QueryBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class QueryBuilderTest extends TestCase
{
    public function testBasicSelect(): void
    {
        $sql = (new QueryBuilder('products'))->toSql();
        $this->assertSame('SELECT * FROM products', $sql);
    }

    public function testColumnsSelect(): void
    {
        $sql = (new QueryBuilder('products', ['id', 'name']))->toSql();
        $this->assertSame('SELECT id, name FROM products', $sql);
    }

    public function testWhereAddsParam(): void
    {
        $qb = (new QueryBuilder('products'))->where('status', '=', 'active');
        $this->assertStringContainsString('WHERE', $qb->toSql());
        $this->assertStringContainsString(':status_1', $qb->toSql());
        $this->assertSame('active', $qb->getParams()['status_1']);
    }

    public function testMultipleWheresCombinedWithAnd(): void
    {
        $qb  = (new QueryBuilder('products'))
            ->where('status', '=', 'active')
            ->where('price', '>', 10);
        $sql = $qb->toSql();
        $this->assertStringContainsString('AND', $sql);
    }

    public function testOrWhereUsesor(): void
    {
        $qb  = (new QueryBuilder('products'))
            ->where('status', '=', 'active')
            ->orWhere('featured', '=', 1);
        $sql = $qb->toSql();
        $this->assertStringContainsString('OR', $sql);
    }

    public function testOrderBy(): void
    {
        $sql = (new QueryBuilder('products'))->orderBy('name', 'DESC')->toSql();
        $this->assertStringContainsString('ORDER BY name DESC', $sql);
    }

    public function testInvalidOrderByDirectionThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new QueryBuilder('products'))->orderBy('name', 'RANDOM');
    }

    public function testLimit(): void
    {
        $sql = (new QueryBuilder('products'))->limit(10)->toSql();
        $this->assertStringContainsString('LIMIT 10', $sql);
    }

    public function testLimitZeroThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new QueryBuilder('products'))->limit(0);
    }

    public function testGroupByAndHaving(): void
    {
        $sql = (new QueryBuilder('products'))
            ->groupBy('status')
            ->having('COUNT(*) > 1')
            ->toSql();
        $this->assertStringContainsString('GROUP BY status', $sql);
        $this->assertStringContainsString('HAVING COUNT(*) > 1', $sql);
    }

    public function testLeftJoin(): void
    {
        $sql = (new QueryBuilder('products'))
            ->leftJoin('categories', 'products.category_id = categories.id')
            ->toSql();
        $this->assertStringContainsString('LEFT JOIN categories ON', $sql);
    }

    public function testParamNamesAreUniqueForSameColumn(): void
    {
        // Two conditions on the same column must produce distinct param names
        $qb     = (new QueryBuilder('products'))
            ->where('price', '>', 10)
            ->where('price', '<', 100);
        $params = $qb->getParams();
        $this->assertCount(2, $params, 'Both params must be distinct (counter, not rand)');
    }

    public function testInvalidOperatorThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new QueryBuilder('products'))->where('id', 'DROP', 1);
    }
}
