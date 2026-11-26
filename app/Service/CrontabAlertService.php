<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Service;

use App\Model\CrontabLog;
use App\Model\CrontabTask;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Guzzle\ClientFactory;
use Throwable;

use function Hyperf\Config\config;

/**
 * 定时任务失败告警：连续失败达到阈值时触发一次。
 * 通知渠道：stdout 日志（始终）；配置了 alert_webhook_url 时额外 POST JSON。
 * 告警为旁路能力，webhook 失败只记日志，不影响任务执行。
 */
class CrontabAlertService
{
    public function __construct(
        protected StdoutLoggerInterface $logger,
        protected ClientFactory $clientFactory,
        protected ConfigService $configService
    ) {
    }

    /**
     * 连续失败数达到阈值时触发（每轮失败都会调用，由本方法内部判断是否告警）。
     */
    public function maybeAlert(CrontabTask $task, CrontabLog $log): void
    {
        $threshold = max(1, $this->configService->int(
            'crontab_fail_alert_threshold',
            (int) config('security.crontab_fail_alert_threshold', 3)
        ));
        if ((int) $task->consecutive_failures !== $threshold) {
            // 只在恰好达到阈值时告警一次；此后保持静默，直到下一次成功清零
            return;
        }

        $message = sprintf(
            '定时任务连续失败 %d 次：[%s] %s，最近错误：%s',
            $task->consecutive_failures,
            $task->name,
            $task->handler,
            mb_substr((string) $log->output, 0, 200)
        );
        $this->logger->warning('【任务告警】' . $message);

        $webhook = $this->configService->string('alert_webhook_url', (string) config('security.alert_webhook_url', ''));
        if ($webhook === '') {
            return;
        }

        try {
            $this->clientFactory->create()->post($webhook, [
                'json' => [
                    'type' => 'crontab_fail',
                    'task_id' => (int) $task->id,
                    'task_name' => (string) $task->name,
                    'handler' => (string) $task->handler,
                    'consecutive_failures' => (int) $task->consecutive_failures,
                    'last_error' => mb_substr((string) $log->output, 0, 500),
                    'time' => date('Y-m-d H:i:s'),
                ],
                'timeout' => 5,
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('任务告警 webhook 推送失败：' . $e->getMessage());
        }
    }
}
