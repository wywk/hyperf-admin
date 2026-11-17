<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Service;

use App\Model\MallGoods;
use Hyperf\DbConnection\Db;

/**
 * MallGoods 业务服务（由代码生成器生成，业务逻辑在这里扩展）。
 */
class MallGoodsService
{
    /**
     * 分页列表。
     */
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $query = MallGoods::query()->orderByDesc('id');

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (! empty($filters['price'])) {
            $query->where('price', $filters['price']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', 'like', '%'.$filters['status'].'%');
        }

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $query->forPage($page, $pageSize)->get(),
        ];
    }

    public function create(array $data): MallGoods
    {
        return MallGoods::query()->create($data);
    }

    public function update(int $id, array $data): MallGoods
    {
        /** @var MallGoods $model */
        $model = MallGoods::query()->findOrFail($id);
        $model->fill($data)->save();

        return $model;
    }

    public function delete(int $id): void
    {
        MallGoods::query()->where('id', $id)->delete();
    }
}
