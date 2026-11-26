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
 * 登录审计日志：只记录认证相关事件，不记录密码等敏感内容。
 */
class LoginLog extends Model
{
    public const EVENT_LOGIN_SUCCESS = 'login_success';

    public const EVENT_LOGIN_FAILED = 'login_failed';

    public const EVENT_LOGIN_LOCKED = 'login_locked';

    public const EVENT_LOGIN_DISABLED = 'login_disabled';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_REFRESH_REPLAY = 'refresh_replay';

    /** 登录风控等级 */
    public const RISK_NORMAL = 0;
    public const RISK_LOW = 1;
    public const RISK_MEDIUM = 2;
    public const RISK_HIGH = 3;

    public const RISK_LABELS = [
        self::RISK_NORMAL => '正常',
        self::RISK_LOW => '低风险',
        self::RISK_MEDIUM => '中风险',
        self::RISK_HIGH => '高风险',
    ];

    /**
     * 表里只有 created_at，没有 updated_at。
     */
    public bool $timestamps = false;

    protected ?string $table = 'login_logs';

    protected array $fillable = [
        'user_id',
        'username',
        'event',
        'ip',
        'user_agent',
        'message',
        'risk_level',
        'risk_reasons',
        'created_at',
    ];

    protected array $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'risk_level' => 'integer',
        'created_at' => 'datetime',
    ];

    public function riskText(): string
    {
        return self::RISK_LABELS[$this->risk_level] ?? (string) $this->risk_level;
    }

    public function eventText(): string
    {
        return match ($this->event) {
            self::EVENT_LOGIN_SUCCESS => '登录成功',
            self::EVENT_LOGIN_FAILED => '密码错误',
            self::EVENT_LOGIN_LOCKED => '触发登录限流',
            self::EVENT_LOGIN_DISABLED => '账号已禁用',
            self::EVENT_LOGOUT => '退出登录',
            self::EVENT_REFRESH_REPLAY => '刷新凭证重放',
            default => (string) $this->event,
        };
    }
}
