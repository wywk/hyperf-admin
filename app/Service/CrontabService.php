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
use Cron\CronExpression;
use Hyperf\Contract\StdoutLoggerInterface;
use Throwable;

/**
 * 定时任务管理：CRUD 与日志查询。
 */
class CrontabService
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * 校验 cron 表达式合法性。
     */
    public function isValidRule(string $rule): bool
    {
        return CronExpression::isValidExpression($rule);
    }

    /**
     * 任务分页。
     *
     * @return array{total: int, page: int, page_size: int, items: array<int, array<string, mixed>>}
     */
    public function paginateTasks(array $filters, int $page, int $pageSize): array
    {
        $query = CrontabTask::query();

        if (! empty($filters['name'])) {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }
        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', (int) $filters['status']);
        }

        $total = (int) $query->count();
        $rows = $query->orderBy('id')->forPage($page, $pageSize)->get();

        $items = [];
        foreach ($rows as $row) {
            /* @var CrontabTask $row */
            $items[] = $this->formatTask($row);
        }

        return ['total' => $total, 'page' => $page, 'page_size' => $pageSize, 'items' => $items];
    }

    /**
     * 执行日志分页。
     *
     * @return array{total: int, page: int, page_size: int, items: array<int, array<string, mixed>>}
     */
    public function paginateLogs(int $taskId, int $page, int $pageSize): array
    {
        $query = CrontabLog::query()->where('task_id', $taskId);

        $total = (int) $query->count();
        $rows = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        $items = [];
        foreach ($rows as $row) {
            /* @var CrontabLog $row */
            $items[] = [
                'id' => (int) $row->id,
                'task_id' => (int) $row->task_id,
                'status' => (int) $row->status,
                'status_text' => $row->statusText(),
                'output' => (string) $row->output,
                'cost_ms' => (int) $row->cost_ms,
                'created_at' => $row->created_at?->toDateTimeString(),
            ];
        }

        return ['total' => $total, 'page' => $page, 'page_size' => $pageSize, 'items' => $items];
    }

    /**
     * 下一次运行时间（供前端展示）。
     */
    public function nextRunAt(CrontabTask $task): ?string
    {
        if (! $task->isEnabled() || ! CronExpression::isValidExpression($task->rule)) {
            return null;
        }

        try {
            return CronExpression::factory($task->rule)
                ->getNextRunDate(new \DateTimeImmutable('now'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    public function formatTask(CrontabTask $task): array
    {
        return [
            'id' => (int) $task->id,
            'name' => (string) $task->name,
            'handler' => (string) $task->handler,
            'rule' => (string) $task->rule,
            'params' => $task->params,
            'status' => (int) $task->status,
            'consecutive_failures' => (int) $task->consecutive_failures,
            'last_run_at' => $task->last_run_at?->toDateTimeString(),
            'next_run_at' => $this->nextRunAt($task),
            'created_at' => $task->created_at?->toDateTimeString(),
            'updated_at' => $task->updated_at?->toDateTimeString(),
        ];
    }
}
