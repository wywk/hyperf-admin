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

use App\Model\MallOrder;

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

        if (isset($filters['order_no']) && $filters['order_no'] !== null && $filters['order_no'] !== '') {
            $query->where('order_no', 'like', '%' . $filters['order_no'] . '%');
        }
        if (isset($filters['receiver']) && $filters['receiver'] !== null && $filters['receiver'] !== '') {
            $query->where('receiver', 'like', '%' . $filters['receiver'] . '%');
        }
        if (isset($filters['phone']) && $filters['phone'] !== null && $filters['phone'] !== '') {
            $query->where('phone', 'like', '%' . $filters['phone'] . '%');
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
