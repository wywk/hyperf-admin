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
 * 数据表 mall_goods 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int $id
 */
class MallGoods extends Model
{
    protected ?string $table = 'mall_goods';

    protected array $fillable = [
        'name',
        'category_id',
        'price',
        'stock',
        'status',
        'cover',
        'summary',
    ];

    protected array $casts = [
        'category_id' => 'float',
        'price' => 'float',
        'stock' => 'float',
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
