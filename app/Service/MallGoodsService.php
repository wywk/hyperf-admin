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

        if (isset($filters['name']) && $filters['name'] !== null && $filters['name'] !== '') {
            $query->where('name', 'like', '%'.$filters['name'].'%');
        }
        if (isset($filters['category_id']) && $filters['category_id'] !== null && $filters['category_id'] !== '') {
            $query->where('category_id', $filters['category_id']);
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

    public function create(array $data): MallGoods
    {
        return MallGoods::query()->create($data);
    }

    public function update(int $id, array $data): MallGoods
    {
        /** @var MallGoods $model */
        $model = MallGoods::query()->findOrFail($id);
        $model->fill($data)->save();
        $this->syncStockFromSkus($id);

        return $model;
    }

    public function delete(int $id): void
    {
        MallGoods::query()->where('id', $id)->delete();
    }

    /**
     * 已配置 SKU 的商品，库存以 SKU 汇总为准（覆盖手动传入的 stock）。
     */
    private function syncStockFromSkus(int $goodsId): void
    {
        $sum = (int) MallGoodsSku::query()->where('goods_id', $goodsId)->sum('stock');
        if ($sum > 0) {
            MallGoods::query()->where('id', $goodsId)->update(['stock' => $sum]);
        }
    }
}
