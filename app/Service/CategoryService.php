<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\Category;
use Generator;
use Hyperf\Database\Exception\QueryException;
use Hyperf\DbConnection\Db;

/**
 * Category 业务服务（由代码生成器生成，业务逻辑在这里扩展）。
 */
class CategoryService
{
    /**
     * 分页列表。
     */
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $query = Category::query()->orderByDesc('id');

        if (isset($filters['name']) && $filters['name'] !== null && $filters['name'] !== '') {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
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
     * 全量列表（树形模块）：不分页，按 parent_id、id 排序，树结构由前端组装。
     */
    public function listAll(array $filters): array
    {
        $query = Category::query()->orderBy('parent_id')->orderBy('id');

        if (isset($filters['name']) && $filters['name'] !== null && $filters['name'] !== '') {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }
        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        $items = $query->get();

        return ['items' => $items, 'total' => $items->count()];
    }

    /**
     * 导出全量数据：与列表共用筛选条件，按 id 升序每批 500 条产出（Generator，内存友好）。
     */
    public function exportRows(array $filters): Generator
    {
        $page = 1;
        do {
            $query = Category::query()->orderBy('id');

            if (isset($filters['name']) && $filters['name'] !== null && $filters['name'] !== '') {
                $query->where('name', 'like', '%' . $filters['name'] . '%');
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

    public function create(array $data): Category
    {
        return Category::query()->create($data);
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
                    Category::query()->create($row);
                    ++$count;
                }
            });
        } catch (QueryException $e) {
            // 逐行校验覆盖不到的数据库层错误（列超长/非空约束等），转成可读的业务异常
            throw new BusinessException(ApiCode::FAILED, '导入失败：部分数据与表结构不匹配（' . $e->getMessage() . '）');
        }

        return $count;
    }

    public function update(int $id, array $data): Category
    {
        if (isset($data['parent_id']) && (int) $data['parent_id'] === $id) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '上级不能选择自己');
        }

        /** @var Category $model */
        $model = Category::query()->findOrFail($id);
        $model->fill($data)->save();

        return $model;
    }

    public function delete(int $id): void
    {
        if (Category::query()->where('parent_id', $id)->exists()) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '存在下级，请先删除下级');
        }

        Category::query()->where('id', $id)->delete();
    }

    /**
     * 批量删除。
     *
     * @param array<int, int> $ids
     * @return int 实际删除的行数
     */
    public function deleteByIds(array $ids): int
    {
        return Category::query()->whereIn('id', $ids)->delete();
    }
}
