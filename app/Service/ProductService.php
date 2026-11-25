<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Service;

use App\Model\Product;
use Hyperf\DbConnection\Db;

/**
 * Product 业务服务（由代码生成器生成，业务逻辑在这里扩展）。
 */
class ProductService
{
    /**
     * 分页列表。
     */
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $query = Product::query()->orderByDesc('id');

        if (isset($filters['name']) && $filters['name'] !== null && $filters['name'] !== '') {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }
        if (isset($filters['price']) && $filters['price'] !== null && $filters['price'] !== '') {
            $query->where('price', $filters['price']);
        }
        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $query->forPage($page, $pageSize)->get(),
        ];
    }

    public function create(array $data): Product
    {
        return Product::query()->create($data);
    }

    public function update(int $id, array $data): Product
    {
        /** @var Product $model */
        $model = Product::query()->findOrFail($id);
        $model->fill($data)->save();

        return $model;
    }

    public function delete(int $id): void
    {
        Product::query()->where('id', $id)->delete();
    }
}
