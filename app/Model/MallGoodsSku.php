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

namespace App\Model;

/**
 * 数据表 mall_goods_skus 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int $id
 */
class MallGoodsSku extends Model
{
    protected ?string $table = 'mall_goods_skus';

    protected array $fillable = [
        'goods_id',
        'sku_code',
        'specs',
        'price',
        'stock',
        'status',
    ];

    protected array $casts = [
        'goods_id' => 'float',
        'price' => 'float',
        'stock' => 'float',
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
