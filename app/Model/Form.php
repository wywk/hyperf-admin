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

use Carbon\Carbon;

/**
 * 表单设计器的表单定义（JSON Schema 存 schema 字段）。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property array $schema
 * @property string $description
 * @property int $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Form extends Model
{
    protected ?string $table = 'forms';

    protected array $fillable = [
        'name',
        'code',
        'schema',
        'description',
        'status',
    ];

    protected array $casts = [
        'id' => 'integer',
        'schema' => 'array',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
