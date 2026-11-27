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

namespace App\Process;

use App\Crontab\CrontabExecutor;
use App\Model\CrontabTask;
use Cron\CronExpression;
use DateTimeImmutable;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Process\AbstractProcess;
use Hyperf\Redis\RedisFactory;
use Throwable;

/**
 * 定时任务调度进程：每秒 tick，检查启用中且到期的任务并交给执行器。
 * 到期判定基于 last_run_at 与 cron 表达式的下一次运行时间；
 * 手动触发的执行不走本进程（HTTP 同步执行）。
 */
class CrontabProcess extends AbstractProcess
{
    /**
     * 每个 worker 都会跑一份本进程，用「任务 ID + 执行窗口分钟」的 Redis 锁
     * 保证同一任务同一窗口只被一个 worker 执行；TTL 300s 自然过期。
     */
    private const LOCK_PREFIX = 'crontab:lock:';

    public string $name = 'crontab-scheduler';

    #[Inject]
    protected CrontabExecutor $executor;

    #[Inject]
    protected StdoutLoggerInterface $logger;

    #[Inject]
    protected RedisFactory $redisFactory;

    public function handle(): void
    {
        while (true) {
            $this->tick();
            sleep(1);
        }
    }

    /**
     * 单轮扫描。
     */
    public function tick(): void
    {
        $tasks = CrontabTask::query()->where('status', CrontabTask::STATUS_ENABLED)->get();

        foreach ($tasks as $task) {
            /* @var CrontabTask $task */
            $next = $this->nextRun($task);
            if ($next === null) {
                continue;
            }

            // 同一执行窗口只放行一个 worker
            $lockKey = self::LOCK_PREFIX . $task->id . ':' . $next->format('YmdHi');
            $redis = $this->redisFactory->get('default');
            if (! $redis->set($lockKey, '1', ['nx', 'ex' => 300])) {
                continue;
            }

            $this->executor->run($task);
        }
    }

    /**
     * 到期判定：以 last_run_at（无记录则取任务创建时间）为基准，
     * cron 表达式的下一次运行时间已过即视为到期；返回该次运行时间（作为窗口标识）。
     */
    public function nextRun(CrontabTask $task, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        if (! CronExpression::isValidExpression($task->rule)) {
            return null;
        }

        $now ??= new DateTimeImmutable();
        $base = $task->last_run_at ?? $task->created_at ?? $now;
        if (! $base instanceof DateTimeImmutable) {
            $base = new DateTimeImmutable($base->format('Y-m-d H:i:s'));
        }

        try {
            $next = CronExpression::factory($task->rule)->getNextRunDate($base);
        } catch (Throwable) {
            // 表达式合法但无下一次运行时间（极端情况），视为不到期
            return null;
        }

        $next = DateTimeImmutable::createFromInterface($next);

        return $next->getTimestamp() <= $now->getTimestamp() ? $next : null;
    }
}
