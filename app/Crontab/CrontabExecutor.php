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
use App\Model\CrontabTask;
use Cron\CronExpression;
use Hyperf\Contract\StdoutLoggerInterface;
use Throwable;

/**
 * 定时任务执行器：给定任务实例并执行，记录日志与连续失败数。
 * 供调度进程（外部先按窗口加锁）与手动触发接口共用。
 */
class CrontabExecutor
{
    public function __construct(
        protected StdoutLoggerInterface $logger,
        protected \App\Service\CrontabAlertService $alertService
    ) {
    }

    /**
     * 执行一个任务（不判断是否到期，到期判断与防重锁在调度器）。
     */
    public function run(CrontabTask $task): CrontabLog
    {
        $start = microtime(true);
        try {
            $handler = $this->resolveHandler($task->handler);
            $output = $handler->execute($task->params ?? []);
            $log = $this->log($task, CrontabLog::STATUS_SUCCESS, mb_substr($output, 0, 65535), (int) round((microtime(true) - $start) * 1000));
        } catch (Throwable $e) {
            $log = $this->log($task, CrontabLog::STATUS_FAIL, mb_substr($e->getMessage(), 0, 65535), (int) round((microtime(true) - $start) * 1000));
        }

        return $log;
    }

    /**
     * handler 存类名：必须在容器可实例化且实现 TaskInterface，防止任意代码执行。
     */
    private function resolveHandler(string $handlerClass): TaskInterface
    {
        if (! class_exists($handlerClass)) {
            throw new \InvalidArgumentException('任务类不存在：' . $handlerClass);
        }
        if (! is_subclass_of($handlerClass, TaskInterface::class)) {
            throw new \InvalidArgumentException('任务类必须实现 ' . TaskInterface::class);
        }

        return new $handlerClass();
    }

    private function log(CrontabTask $task, int $status, string $output, int $costMs): CrontabLog
    {
        $log = CrontabLog::query()->create([
            'task_id' => $task->id,
            'status' => $status,
            'output' => $output,
            'cost_ms' => $costMs,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $task->last_run_at = date('Y-m-d H:i:s');
        // 连续失败计数：成功清零，失败累加；达到阈值触发一次告警
        if ($status === CrontabLog::STATUS_SUCCESS) {
            $task->consecutive_failures = 0;
        } else {
            $task->consecutive_failures = (int) $task->consecutive_failures + 1;
            $this->logger->warning(sprintf('定时任务执行失败（id=%d handler=%s）：%s', $task->id, $task->handler, $output));
            $this->alertService->maybeAlert($task, $log);
        }
        $task->save();

        return $log;
    }
}
