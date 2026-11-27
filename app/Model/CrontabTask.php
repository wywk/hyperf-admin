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
 * 定时任务：任务定义与调度状态。
 */
class CrontabTask extends Model
{
    public const STATUS_ENABLED = 1;

    public const STATUS_DISABLED = 0;

    protected ?string $table = 'crontab_tasks';

    protected array $fillable = [
        'name',
        'handler',
        'rule',
        'params',
        'status',
        'consecutive_failures',
        'last_run_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'consecutive_failures' => 'integer',
        'params' => 'array',
        'last_run_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isEnabled(): bool
    {
        return (int) $this->status === self::STATUS_ENABLED;
    }
}
