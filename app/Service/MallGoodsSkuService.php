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
        $model = MallGoodsSku::query()->create($data);
        $this->syncGoodsStock((int) $model->goods_id);

        return $model;
    }

    public function update(int $id, array $data): MallGoodsSku
    {
        /** @var MallGoodsSku $model */
        $model = MallGoodsSku::query()->findOrFail($id);
        $model->fill($data)->save();
        $this->syncGoodsStock((int) $model->goods_id);

        return $model;
    }

    public function delete(int $id): void
    {
        /** @var MallGoodsSku|null $model */
        $model = MallGoodsSku::query()->find($id);
        MallGoodsSku::query()->where('id', $id)->delete();
        if ($model !== null) {
            $this->syncGoodsStock((int) $model->goods_id);
        }
    }

    /**
     * 商品库存 = 其 SKU 库存之和；SKU 任何增删改后回写商品表。
     */
    private function syncGoodsStock(int $goodsId): void
    {
        $sum = (int) MallGoodsSku::query()->where('goods_id', $goodsId)->sum('stock');
        MallGoods::query()->where('id', $goodsId)->update(['stock' => $sum]);
    }
}
