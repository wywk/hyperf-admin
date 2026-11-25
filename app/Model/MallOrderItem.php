<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Model;

/**
 * 数据表 mall_order_items 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int $id
 */
class MallOrderItem extends Model
{
    protected ?string $table = 'mall_order_items';

    protected array $fillable = [
        'order_id',
        'goods_id',
        'goods_name',
        'price',
        'quantity',
        'amount',
    ];

    protected array $casts = [
        'order_id' => 'float',
        'goods_id' => 'float',
        'price' => 'float',
        'quantity' => 'float',
        'amount' => 'float',
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
