<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Service;

use App\Model\CrontabTask;
use Cron\CronExpression;
use Throwable;

use function Hyperf\Config\config;

/**
 * 系统健康聚合：MySQL / Redis / 运行时 / 队列积压 / 磁盘 / 定时任务 /
 * 近 1 小时接口耗时 / 最近错误日志，一次拉齐给监控页展示。
 *
 * 只读、无副作用；任何单项失败都降级为 error 字段，不影响其它项。
 */
class HealthService
{
    public function __construct(protected \Hyperf\Redis\Redis $redis)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return [
            'database' => $this->database(),
            'redis' => $this->redisInfo(),
            'runtime' => $this->runtime(),
            'queue' => $this->queue(),
            'disk' => $this->disk(),
            'crontab' => $this->crontab(),
            'api_stats' => $this->apiStats(),
            'recent_errors' => $this->recentErrors(),
            'collected_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            $pdo = \Hyperf\DbConnection\Db::connection()->getPdo();

            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $threads = (int) $pdo->query("SHOW STATUS LIKE 'Threads_connected'")->fetchColumn(1);
            $slowQueries = (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Slow_queries'")->fetchColumn(1);
            $uptime = (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Uptime'")->fetchColumn(1);

            return [
                'version' => $version,
                'threads_connected' => $threads,
                'slow_queries' => $slowQueries,
                'uptime_days' => round($uptime / 86400, 1),
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function redisInfo(): array
    {
        try {
            $info = $this->redis->info();
            $hits = (int) ($info['keyspace_hits'] ?? 0);
            $misses = (int) ($info['keyspace_misses'] ?? 0);
            $total = $hits + $misses;

            return [
                'used_memory_human' => (string) ($info['used_memory_human'] ?? '-'),
                'connected_clients' => (int) ($info['connected_clients'] ?? 0),
                'total_keys' => (int) $this->redis->dbsize(),
                'hit_rate' => $total > 0 ? round($hits * 100 / $total, 1) : null,
                'uptime_days' => isset($info['uptime_in_days']) ? (int) $info['uptime_in_days'] : null,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runtime(): array
    {
        return [
            'php' => PHP_VERSION,
            'swoole' => phpversion('swoole') ?: '-',
            'memory_used' => round(memory_get_usage(true) / 1048576, 1),
            'memory_peak' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
    }

    /**
     * 队列积压：等待 / 延迟 / 失败三个队列的长度。
     *
     * @return array<string, int|string>
     */
    private function queue(): array
    {
        $channel = (string) config('async_queue.default.channel', 'async-queue');

        try {
            return [
                'waiting' => (int) $this->redis->lLen($channel . ':waiting'),
                'delayed' => (int) $this->redis->zCard($channel . ':delayed'),
                'failed' => (int) $this->redis->zCard($channel . ':failed'),
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function disk(): array
    {
        try {
            $path = BASE_PATH;
            $total = (float) disk_total_space($path);
            $free = (float) disk_free_space($path);

            return [
                'total_gb' => round($total / 1073741824, 1),
                'free_gb' => round($free / 1073741824, 1),
                'used_percent' => $total > 0 ? round(($total - $free) * 100 / $total, 1) : null,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 定时任务：启用数 / 连续失败任务明细 / 各任务下次执行时间。
     *
     * @return array<string, mixed>
     */
    private function crontab(): array
    {
        try {
            $tasks = CrontabTask::query()->where('status', CrontabTask::STATUS_ENABLED)->orderBy('id')->get();
            $items = [];
            foreach ($tasks as $task) {
                $next = null;
                try {
                    if (CronExpression::isValidExpression((string) $task->rule)) {
                        $next = CronExpression::factory((string) $task->rule)
                            ->getNextRunDate('now')
                            ->format('Y-m-d H:i:s');
                    }
                } catch (Throwable) {
                }

                $items[] = [
                    'id' => (int) $task->id,
                    'name' => (string) $task->name,
                    'rule' => (string) $task->rule,
                    'consecutive_failures' => (int) $task->consecutive_failures,
                    'last_run_at' => $task->last_run_at,
                    'next_run_at' => $next,
                ];
            }

            return [
                'enabled' => count($items),
                'failing' => count(array_filter($items, static fn ($item) => $item['consecutive_failures'] > 0)),
                'items' => $items,
            ];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 近 1 小时写操作耗时统计（复用操作日志，不额外埋点）。
     *
     * @return array<string, mixed>
     */
    private function apiStats(): array
    {
        try {
            $since = date('Y-m-d H:i:s', time() - 3600);
            $rows = \Hyperf\DbConnection\Db::table('operation_logs')
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')
                ->limit(5000)
                ->get(['path', 'cost_ms'])
                ->map(static fn ($row) => ['path' => (string) $row->path, 'cost_ms' => (int) $row->cost_ms])
                ->all();

            return self::computeStats($rows);
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 耗时统计（纯函数，便于单测）：总数 / 平均 / P95 / 按平均耗时倒序的最慢路径。
     *
     * @param array<int, array{path: string, cost_ms: int}> $rows
     * @return array{total: int, avg_ms: null|int, p95_ms: null|int, slowest: array<int, array{path: string, count: int, avg_ms: int, max_ms: int}>}
     */
    public static function computeStats(array $rows): array
    {
        $total = count($rows);
        if ($total === 0) {
            return ['total' => 0, 'avg_ms' => null, 'p95_ms' => null, 'slowest' => []];
        }

        $costs = array_column($rows, 'cost_ms');
        sort($costs);
        $byPath = [];
        foreach ($rows as $row) {
            $byPath[$row['path']][] = $row['cost_ms'];
        }

        $slowest = [];
        foreach ($byPath as $path => $list) {
            $slowest[] = [
                'path' => $path,
                'count' => count($list),
                'avg_ms' => (int) round(array_sum($list) / count($list)),
                'max_ms' => max($list),
            ];
        }
        usort($slowest, static fn ($a, $b) => $b['avg_ms'] <=> $a['avg_ms']);

        return [
            'total' => $total,
            'avg_ms' => (int) round(array_sum($costs) / $total),
            'p95_ms' => (int) $costs[min($total - 1, (int) floor($total * 0.95))],
            'slowest' => array_slice($slowest, 0, 10),
        ];
    }

    /**
     * 最近错误日志：tail 日志文件，取最后 20 条 ERROR/WARNING 行。
     *
     * @return array<int, string>
     */
    private function recentErrors(): array
    {
        $file = BASE_PATH . '/runtime/logs/hyperf.log';
        if (! is_file($file)) {
            return [];
        }

        try {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                return [];
            }
            $tail = array_slice($lines, -300);
            $errors = array_values(array_filter(
                $tail,
                static fn ($line) => str_contains($line, '] ERROR:') || str_contains($line, '] WARNING:')
            ));

            return array_slice($errors, -20);
        } catch (Throwable) {
            return [];
        }
    }
}
