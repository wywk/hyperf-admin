<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Model;

/**
 * 部门：树形结构，用物化路径 path（形如 '/1/3/8/'）查下级。
 */
class Department extends Model
{
    public const STATUS_ENABLED = 1;

    public const STATUS_DISABLED = 0;

    protected ?string $table = 'departments';

    protected array $fillable = [
        'parent_id',
        'name',
        'code',
        'leader',
        'phone',
        'sort',
        'status',
        'path',
    ];

    protected array $casts = [
        'id' => 'integer',
        'parent_id' => 'integer',
        'sort' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isEnabled(): bool
    {
        return (int) $this->status === self::STATUS_ENABLED;
    }
}
