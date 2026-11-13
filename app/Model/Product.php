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
 * 数据表 demo_products 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int $id
 */
class Product extends Model
{
    protected ?string $table = 'demo_products';

    protected array $fillable = [
        'name',
        'category',
        'price',
        'stock',
        'status',
        'description',
    ];

    protected array $casts = [
        'id' => 'float',
        'price' => 'float',
        'stock' => 'float',
        'status' => 'boolean',
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
