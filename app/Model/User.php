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

class User extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = [
        'username',
        'password',
        'nickname',
        'email',
        'status',
        'role',
    ];

    /**
     * 密码哈希不允许出现在任何序列化输出中。
     */
    protected array $hidden = [
        'password',
    ];

    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'totp_enabled' => 'integer',
        'last_login_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isEnabled(): bool
    {
        return (int) $this->status === 1;
    }
}
