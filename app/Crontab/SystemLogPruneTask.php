<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Crontab;

use App\Model\CrontabLog;
use App\Model\LoginLog;
use App\Model\OperationLog;
use App\Service\ConfigService;
use Hyperf\Contract\StdoutLoggerInterface;

use function Hyperf\Config\config;

/**
 * 系统日志清理任务：按保留天数清理登录审计 / 操作日志 / 定时任务执行日志。
 *
 * 定时任务配置示例：
 *   handler = App\Crontab\SystemLogPruneTask
 *   rule    = 30 3 * * *
 *   params  = {"login_days": 90, "operation_days": 180, "crontab_days": 90}
 * params 缺省时取 config/security.php 的 retention 配置。
 */
class SystemLogPruneTask implements TaskInterface
{
    public function __construct(protected StdoutLoggerInterface $logger, protected ConfigService $configService)
    {
    }

    public function execute(array $params): string
    {
        // 优先用任务参数，其次系统配置，最后 .env
        $loginDays = max(1, (int) ($params['login_days'] ?? $this->configService->int(
            'login_log_retention_days',
            (int) config('security.login_log_retention_days', 90)
        )));
        $operationDays = max(1, (int) ($params['operation_days'] ?? $this->configService->int(
            'operation_log_retention_days',
            (int) config('security.operation_log_retention_days', 180)
        )));
        $crontabDays = max(1, (int) ($params['crontab_days'] ?? $this->configService->int(
            'operation_log_retention_days',
            (int) config('security.operation_log_retention_days', 180)
        )));

        $threshold = static fn (int $days): string => date('Y-m-d H:i:s', time() - $days * 86400);

        $loginDeleted = LoginLog::query()->where('created_at', '<', $threshold($loginDays))->delete();
        $operationDeleted = OperationLog::query()->where('created_at', '<', $threshold($operationDays))->delete();
        $crontabDeleted = CrontabLog::query()->where('created_at', '<', $threshold($crontabDays))->delete();

        $message = sprintf(
            '登录日志清理 %d 条(保 %d 天)，操作日志清理 %d 条(保 %d 天)，任务日志清理 %d 条(保 %d 天)',
            $loginDeleted,
            $loginDays,
            $operationDeleted,
            $operationDays,
            $crontabDeleted,
            $crontabDays
        );
        $this->logger->info('系统日志清理任务：' . $message);

        return $message;
    }
}
