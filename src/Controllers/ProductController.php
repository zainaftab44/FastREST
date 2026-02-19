<?php

declare(strict_types=1);

namespace FastREST\Controllers;

use FastREST\Database\Database;
use FastREST\Database\QueryBuilder;
use FastREST\Exceptions\HttpException;
use FastREST\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Product resource controller.
 *
 * All methods are regular instance methods (no more static).
 * Dependencies are injected via the constructor — the DI container
 * resolves this automatically.
 *
 * Route parameters (e.g. {id}) are available as request attributes:
 *   $id = $request->getAttribute('id');
 */
class ProductController
{
    public function __construct(
        private readonly Database        $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /products
     * List products with optional filtering via query string.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        $qb = (new QueryBuilder('products', ['id', 'name', 'price', 'status']))
            ->orderBy('name', 'ASC')
            ->limit(50);

        // Optional filtering by status
        if (!empty($queryParams['status'])) {
            $qb->where('status', '=', $queryParams['status']);
        }

        $rows = $qb->execute($this->db);

        $this->logger->info('Products listed', ['count' => count($rows)]);

        return Response::json([
            'status' => 'success',
            'data'   => $rows,
            'count'  => count($rows),
        ]);
    }

    /**
     * GET /products/{id}
     * Fetch a single product by its primary key.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id');

        $rows = $this->db->select('products', [], ['id' => $id]);

        if (empty($rows)) {
            throw new HttpException(404, "Product with id $id not found.");
        }

        return Response::json(['status' => 'success', 'data' => $rows[0]]);
    }

    /**
     * POST /products
     * Create a new product from the JSON request body.
     */
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) $request->getParsedBody();

        $this->validate($body, ['name', 'price']);

        $data = [
            'name'   => $body['name'],
            'price'  => $body['price'],
            'status' => $body['status'] ?? 'active',
        ];

        $inserted = $this->db->insert('products', $data);

        if (!$inserted) {
            throw new HttpException(500, 'Failed to create product.');
        }

        $this->logger->info('Product created', ['name' => $data['name']]);

        return Response::json(['status' => 'success', 'message' => 'Product created.'], 201);
    }

    /**
     * PUT /products/{id}
     * Replace a product's data.
     */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $id   = (int) $request->getAttribute('id');
        $body = (array) $request->getParsedBody();

        $this->validate($body, ['name', 'price']);

        $updated = $this->db->update('products', [
            'name'  => $body['name'],
            'price' => $body['price'],
        ], ['id' => $id]);

        if (!$updated) {
            throw new HttpException(404, "Product with id $id not found or no change.");
        }

        return Response::json(['status' => 'success', 'message' => 'Product updated.']);
    }

    /**
     * DELETE /products/{id}
     * Remove a product.
     */
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        $id = (int) $request->getAttribute('id');

        $deleted = $this->db->delete('products', ['id' => $id]);

        if (!$deleted) {
            throw new HttpException(404, "Product with id $id not found.");
        }

        $this->logger->info('Product deleted', ['id' => $id]);

        return Response::noContent(); // 204
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Assert that required fields exist in the payload.
     * Throws a 422 Unprocessable Entity if any are missing.
     *
     * @param array<string, mixed> $body
     * @param string[]             $required
     */
    private function validate(array $body, array $required): void
    {
        $missing = array_filter($required, static fn($field) => !isset($body[$field]) || $body[$field] === '');

        if (!empty($missing)) {
            throw new HttpException(422, 'Missing required fields: ' . implode(', ', $missing));
        }
    }
}
