<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\Product;
use Hyperf\Database\Exception\QueryException;
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

    /**
     * 导出全量数据：与列表共用筛选条件，按 id 升序每批 500 条产出（Generator，内存友好）。
     */
    public function exportRows(array $filters): \Generator
    {
        $page = 1;
        do {
            $query = Product::query()->orderBy('id');

        if (isset($filters['name']) && $filters['name'] !== null && $filters['name'] !== '') {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }
        if (isset($filters['price']) && $filters['price'] !== null && $filters['price'] !== '') {
            $query->where('price', $filters['price']);
        }
        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

            $rows = $query->forPage($page, 500)->get();
            foreach ($rows as $row) {
                yield $row;
            }
            ++$page;
        } while ($rows->count() === 500);
    }

    public function create(array $data): Product
    {
        return Product::query()->create($data);
    }

    /**
     * Excel 导入批量落库：已在控制器逐行校验通过的行，统一在事务内插入。
     *
     * @param array<int, array<string, mixed>> $rows 字段 => 值
     * @return int 成功插入的行数
     */
    public function insertRows(array $rows): int
    {
        $count = 0;
        try {
            Db::transaction(function () use ($rows, &$count): void {
                foreach ($rows as $row) {
                    Product::query()->create($row);
                    ++$count;
                }
            });
        } catch (QueryException $e) {
            // 逐行校验覆盖不到的数据库层错误（列超长/非空约束等），转成可读的业务异常
            throw new BusinessException(ApiCode::FAILED, '导入失败：部分数据与表结构不匹配（' . $e->getMessage() . '）');
        }

        return $count;
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

    /**
     * 批量删除。
     *
     * @param array<int, int> $ids
     * @return int 实际删除的行数
     */
    public function deleteByIds(array $ids): int
    {
        return Product::query()->whereIn('id', $ids)->delete();
    }
}
