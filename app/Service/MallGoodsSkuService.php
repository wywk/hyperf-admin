<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Service;

use App\Model\MallGoodsSku;
use Hyperf\DbConnection\Db;

/**
 * MallGoodsSku 业务服务（由代码生成器生成，业务逻辑在这里扩展）。
 */
class MallGoodsSkuService
{
    /**
     * 分页列表。
     */
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $query = MallGoodsSku::query()->orderByDesc('id');

        if (isset($filters['goods_id']) && $filters['goods_id'] !== null && $filters['goods_id'] !== '') {
            $query->where('goods_id', $filters['goods_id']);
        }
        if (isset($filters['sku_code']) && $filters['sku_code'] !== null && $filters['sku_code'] !== '') {
            $query->where('sku_code', 'like', '%'.$filters['sku_code'].'%');
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

    public function create(array $data): MallGoodsSku
    {
        return MallGoodsSku::query()->create($data);
    }

    public function update(int $id, array $data): MallGoodsSku
    {
        /** @var MallGoodsSku $model */
        $model = MallGoodsSku::query()->findOrFail($id);
        $model->fill($data)->save();

        return $model;
    }

    public function delete(int $id): void
    {
        MallGoodsSku::query()->where('id', $id)->delete();
    }
}
