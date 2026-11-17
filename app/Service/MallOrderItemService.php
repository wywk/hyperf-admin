<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Service;

use App\Model\MallOrderItem;
use Hyperf\DbConnection\Db;

/**
 * MallOrderItem 业务服务（由代码生成器生成，业务逻辑在这里扩展）。
 */
class MallOrderItemService
{
    /**
     * 分页列表。
     */
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $query = MallOrderItem::query()->orderByDesc('id');

        if (isset($filters['order_id']) && $filters['order_id'] !== null && $filters['order_id'] !== '') {
            $query->where('order_id', $filters['order_id']);
        }
        if (isset($filters['goods_name']) && $filters['goods_name'] !== null && $filters['goods_name'] !== '') {
            $query->where('goods_name', 'like', '%'.$filters['goods_name'].'%');
        }

        $total = (clone $query)->count();

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $query->forPage($page, $pageSize)->get(),
        ];
    }

    public function create(array $data): MallOrderItem
    {
        return MallOrderItem::query()->create($data);
    }

    public function update(int $id, array $data): MallOrderItem
    {
        /** @var MallOrderItem $model */
        $model = MallOrderItem::query()->findOrFail($id);
        $model->fill($data)->save();

        return $model;
    }

    public function delete(int $id): void
    {
        MallOrderItem::query()->where('id', $id)->delete();
    }
}
