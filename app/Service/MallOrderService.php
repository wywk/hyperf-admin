<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Service;

use App\Model\MallOrder;
use Hyperf\DbConnection\Db;

/**
 * MallOrder 业务服务（由代码生成器生成，业务逻辑在这里扩展）。
 */
class MallOrderService
{
    /**
     * 分页列表。
     */
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $query = MallOrder::query()->orderByDesc('id');

        if (! empty($filters['order_no'])) {
            $query->where('order_no', 'like', '%'.$filters['order_no'].'%');
        }
        if (! empty($filters['receiver'])) {
            $query->where('receiver', 'like', '%'.$filters['receiver'].'%');
        }
        if (! empty($filters['phone'])) {
            $query->where('phone', 'like', '%'.$filters['phone'].'%');
        }
        if (! empty($filters['status'])) {
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

    public function create(array $data): MallOrder
    {
        return MallOrder::query()->create($data);
    }

    public function update(int $id, array $data): MallOrder
    {
        /** @var MallOrder $model */
        $model = MallOrder::query()->findOrFail($id);
        $model->fill($data)->save();

        return $model;
    }

    public function delete(int $id): void
    {
        MallOrder::query()->where('id', $id)->delete();
    }
}
