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
 * 定时任务执行日志。表里只有 created_at，没有 updated_at。
 */
class CrontabLog extends Model
{
    public const STATUS_RUNNING = 0;

    public const STATUS_SUCCESS = 1;

    public const STATUS_FAIL = 2;

    public bool $timestamps = false;

    protected ?string $table = 'crontab_logs';

    protected array $fillable = [
        'task_id',
        'status',
        'output',
        'cost_ms',
        'created_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'task_id' => 'integer',
        'status' => 'integer',
        'cost_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    public function statusText(): string
    {
        return match ((int) $this->status) {
            self::STATUS_RUNNING => '运行中',
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAIL => '失败',
            default => (string) $this->status,
        };
    }
}
