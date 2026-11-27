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

use App\Model\Concerns\LogsAttributeChanges;

/**
 * 数据表 demo_products 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int $id
 */
class Product extends Model
{
    use LogsAttributeChanges;

    protected ?string $table = 'demo_products';

    protected array $fillable = [
        'name',
        'price',
        'stock',
        'status',
    ];

    protected array $casts = [
        'price' => 'float',
        'stock' => 'float',
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
