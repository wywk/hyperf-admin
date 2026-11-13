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
 * 表单设计器的表单定义（JSON Schema 存 schema 字段）。
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property array $schema
 * @property string $description
 * @property int $status
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
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
