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
 * 数据表 mall_orders 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int $id
 */
class MallOrder extends Model
{
    protected ?string $table = 'mall_orders';

    protected array $fillable = [
        'order_no',
        'receiver',
        'phone',
        'amount',
        'status',
        'user_id',
        'address',
        'remark',
    ];

    protected array $casts = [
        'amount' => 'float',
        'user_id' => 'float',
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
