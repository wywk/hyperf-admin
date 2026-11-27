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
