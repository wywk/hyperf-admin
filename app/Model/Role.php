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

use Hyperf\Database\Model\Relations\BelongsToMany;

class Role extends Model
{
    public const SYSTEM_ADMIN = 'admin';

    protected ?string $table = 'roles';

    protected array $fillable = [
        'code',
        'name',
        'description',
        'is_system',
        'sort',
    ];

    protected array $casts = [
        'id' => 'integer',
        'is_system' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联表是 role_permissions，默认推导出来的名字是 permission_role，必须显式指定。
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions',
            'role_id',
            'permission_id'
        );
    }

    public function isSystem(): bool
    {
        return (int) $this->is_system === 1;
    }
}
