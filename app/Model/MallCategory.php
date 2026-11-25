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
 * 数据表 mall_categories 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int $id
 */
class MallCategory extends Model
{
    protected ?string $table = 'mall_categories';

    protected array $fillable = [
        'name',
        'sort',
        'status',
        'description',
    ];

    protected array $casts = [
        'sort' => 'float',
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
