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

use App\Model\MallCategory;

/**
 * MallCategory 业务服务（由代码生成器生成，业务逻辑在这里扩展）。
 */
class MallCategoryService
{
    /**
     * 分页列表。
     */
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $query = MallCategory::query()->orderByDesc('id');

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

    public function create(array $data): MallCategory
    {
        return MallCategory::query()->create($data);
    }

    public function update(int $id, array $data): MallCategory
    {
        /** @var MallCategory $model */
        $model = MallCategory::query()->findOrFail($id);
        $model->fill($data)->save();

        return $model;
    }

    public function delete(int $id): void
    {
        MallCategory::query()->where('id', $id)->delete();
    }
}
