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
 * 站内消息：一条记录只属于一个接收者（群发时按用户展开成多行）。
 */
class Notification extends Model
{
    public const TYPE_SYSTEM = 'system';

    public const TYPE_CRONTAB = 'crontab';

    public const TYPE_ACCOUNT = 'account';

    public const TYPE_SECURITY = 'security';

    public const LEVEL_INFO = 'info';

    public const LEVEL_SUCCESS = 'success';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_ERROR = 'error';

    protected ?string $table = 'notifications';

    protected array $fillable = [
        'user_id',
        'type',
        'level',
        'title',
        'content',
        'link',
        'is_read',
        'read_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'is_read' => 'integer',
        'read_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const TYPES = [
        self::TYPE_SYSTEM => '系统',
        self::TYPE_CRONTAB => '定时任务',
        self::TYPE_ACCOUNT => '账号',
        self::TYPE_SECURITY => '安全',
    ];
}
