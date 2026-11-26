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
 * 数据表 demo_categories 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int $id
 */
class Category extends Model
{
    protected ?string $table = 'demo_categories';

    protected array $fillable = [
        'parent_id',
        'name',
        'sort',
        'status',
    ];

    protected array $casts = [
        'sort' => 'float',
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
