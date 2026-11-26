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
 * 异步任务台账。真正消息体在 Redis 队列，这里记录状态流转供任务中心展示。
 *
 * @property int    $id
 * @property string $type
 * @property int    $status
 * @property null|array $payload
 * @property null|int   $result_file_id
 * @property string $error
 * @property int    $attempts
 * @property null|int $user_id
 * @property null|int $duration_ms
 * @property null|string $created_at
 * @property null|string $updated_at
 * @property null|string $finished_at
 */
class Job extends \Hyperf\Database\Model\Model
{
    public const STATUS_WAITING = 0;
    public const STATUS_EXECUTING = 1;
    public const STATUS_SUCCEEDED = 2;
    public const STATUS_FAILED = 3;

    public const TYPE_EXPORT_LOGIN_LOGS = 'export.login_logs';
    public const TYPE_EXPORT_OPERATION_LOGS = 'export.operation_logs';
    public const TYPE_EXPORT_PRODUCTS = 'export.products';

    /** 任务类型 => 展示名（前端兜底映射，这里做后端权威来源） */
    public const TYPE_LABELS = [
        self::TYPE_EXPORT_LOGIN_LOGS => '导出登录审计',
        self::TYPE_EXPORT_OPERATION_LOGS => '导出操作日志',
        self::TYPE_EXPORT_PRODUCTS => '导出商品',
    ];

    protected ?string $table = 'jobs';

    protected array $fillable = [
        'type', 'status', 'payload', 'result_file_id', 'error', 'attempts', 'user_id', 'duration_ms', 'finished_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'payload' => 'array',
        'result_file_id' => 'integer',
        'attempts' => 'integer',
        'user_id' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function file(): \Hyperf\Database\Model\Relations\HasOne
    {
        return $this->hasOne(File::class, 'id', 'result_file_id');
    }
}
