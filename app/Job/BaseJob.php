<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Job;

use App\Model\Job;
use App\Model\Notification;
use App\Service\NotificationService;
use Hyperf\AsyncQueue\Job as QueueJob;
use Throwable;

/**
 * 异步任务基类：负责把「Redis 队列消息」与「jobs 表台账」绑定。
 *
 * 子类只实现 run()，状态流转（执行中 → 已完成/失败）、耗时统计、
 * 失败原因落库、站内通知都由基类统一处理。
 */
abstract class BaseJob extends QueueJob
{
    public function __construct(public int $jobId)
    {
    }

    /**
     * 子类实现具体业务，返回给 onDone 做收尾（如登记结果文件）。
     */
    abstract public function run(): void;

    public function handle(): void
    {
        $start = microtime(true);

        /** @var null|Job $record */
        $record = Job::query()->find($this->jobId);
        if ($record === null) {
            return; // 台账已被人删了（比如任务被取消），直接丢弃消息
        }

        $record->status = Job::STATUS_EXECUTING;
        ++$record->attempts;
        $record->save();

        try {
            $this->run();

            $record->status = Job::STATUS_SUCCEEDED;
            $record->error = '';
            $record->finished_at = date('Y-m-d H:i:s');
            $record->duration_ms = (int) ((microtime(true) - $start) * 1000);
            $record->save();

            $this->onSuccess($record);
        } catch (Throwable $e) {
            $record->status = Job::STATUS_FAILED;
            $record->error = mb_substr($e->getMessage(), 0, 500);
            $record->finished_at = date('Y-m-d H:i:s');
            $record->duration_ms = (int) ((microtime(true) - $start) * 1000);
            $record->save();

            $this->onFailed($record, $e);
        }
    }

    /**
     * 成功收尾：给提交人推一条站内消息（失败同样）。
     */
    protected function onSuccess(Job $record): void
    {
        $this->notify($record, true);
    }

    protected function onFailed(Job $record, Throwable $e): void
    {
        $this->notify($record, false);
    }

    private function notify(Job $record, bool $ok): void
    {
        if ($record->user_id === null) {
            return;
        }

        $label = Job::TYPE_LABELS[$record->type] ?? $record->type;
        try {
            $svc = \Hyperf\Context\ApplicationContext::getContainer()->get(NotificationService::class);
            $svc->push(
                $record->user_id,
                $ok ? "任务完成：{$label}" : "任务失败：{$label}",
                $ok
                    ? sprintf('耗时 %.1f 秒，请到任务中心下载结果。', ($record->duration_ms ?? 0) / 1000)
                    : sprintf('原因：%s', $record->error),
                'system',
                $ok ? Notification::LEVEL_INFO : Notification::LEVEL_ERROR,
                '/system/jobs'
            );
        } catch (Throwable $e) {
            // 通知是旁路能力，失败不影响任务状态
        }
    }
}
