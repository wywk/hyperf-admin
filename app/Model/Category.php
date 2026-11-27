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
