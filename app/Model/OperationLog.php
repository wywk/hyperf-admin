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
 * 操作日志：记录登录用户的写操作（POST/PUT/DELETE）审计流水。
 * 表里只有 created_at，没有 updated_at。
 */
class OperationLog extends Model
{
    public bool $timestamps = false;

    protected ?string $table = 'operation_logs';

    protected array $fillable = [
        'user_id',
        'username',
        'method',
        'path',
        'action',
        'params',
        'status_code',
        'cost_ms',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'status_code' => 'integer',
        'cost_ms' => 'integer',
        'params' => 'array',
        'created_at' => 'datetime',
    ];
}
