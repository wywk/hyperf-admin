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
use function Hyperf\Support\env;

return [
    // 登录失败限流：同一 IP + 用户名连续失败 max_attempts 次后锁定 decay_seconds 秒
    'login_throttle' => [
        'max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('LOGIN_DECAY_SECONDS', 900),
    ],

    // 是否信任 X-Forwarded-For / X-Real-IP 作为客户端 IP。
    // 只有请求一定经过自己可控的反向代理时才开启，否则客户端可以伪造头绕过限流。
    'trust_proxy' => (bool) env('TRUST_PROXY', false),

    // 是否写登录审计日志（login_logs 表）。写入失败只告警，不影响登录。
    'login_log_enabled' => (bool) env('LOGIN_LOG_ENABLED', true),

    // 审计日志保留天数，配合 `php bin/hyperf.php login-log:prune` 清理
    'login_log_retention_days' => (int) env('LOGIN_LOG_RETENTION_DAYS', 90),

    // 是否写操作日志（operation_logs 表，记录登录用户的写操作）。写入失败只告警。
    'operation_log_enabled' => (bool) env('OPERATION_LOG_ENABLED', true),

    // 操作日志保留天数，预留清理任务使用
    'operation_log_retention_days' => (int) env('OPERATION_LOG_RETENTION_DAYS', 180),

    // 定时任务连续失败多少次后触发告警（每轮失败都会调用，只在恰好达到阈值时告警一次）
    'crontab_fail_alert_threshold' => (int) env('CRONTAB_FAIL_ALERT_THRESHOLD', 3),

    // 任务告警 webhook 地址（可选，POST JSON）；为空则只写 stdout 日志
    'alert_webhook_url' => (string) env('ALERT_WEBHOOK_URL', ''),
];
