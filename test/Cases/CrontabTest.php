<?php

declare(strict_types=1);
/**
 * 定时任务测试：CRUD / cron 表达式校验 / 手动触发执行 / 日志 / 到期判定 / 权限。
 *
 * @internal
 * @coversNothing
 */

namespace HyperfTest\Cases;

use App\Model\CrontabLog;
use App\Process\CrontabProcess;
use Hyperf\Testing\TestCase;
use Psr\Container\ContainerInterface;

use function Hyperf\Support\make;

class CrontabTest extends TestCase
{
    /**
     * handler 有唯一索引，测试前清理同名 handler 残留，保证幂等。
     */
    private function cleanHandlers(array $handlers): void
    {
        foreach ($handlers as $handler) {
            $task = \App\Model\CrontabTask::query()->where('handler', $handler)->first();
            if ($task !== null) {
                CrontabLog::query()->where('task_id', $task->id)->delete();
                $task->delete();
            }
        }
    }

    public function testRequiresLogin(): void
    {
        $this->assertSame(401, $this->get('/api/crontabs')->json('code'));
    }

    public function testRequiresCrontabViewPermission(): void
    {
        $token = $this->loginToken('demo', 'demo123456');
        $this->assertSame(403, $this->get('/api/crontabs', [], ['Authorization' => 'Bearer ' . $token])->json('code'));
    }

    public function testStoreRejectsInvalidRule(): void
    {
        $token = $this->loginToken('admin', 'admin123456');
        $resp = $this->json('/api/crontabs', [
            'name' => '非法表达式任务',
            'handler' => 'App\\Crontab\\DemoReportTask',
            'rule' => 'not-a-cron',
        ], ['Authorization' => 'Bearer ' . $token]);

        $this->assertSame(422, $resp->json('code'));
        $this->assertStringContainsString('cron', (string) $resp->json('message'));
    }

    public function testStoreRunAndLogs(): void
    {
        $token = $this->loginToken('admin', 'admin123456');
        $headers = ['Authorization' => 'Bearer ' . $token];
        $this->cleanHandlers(['App\\Crontab\\DemoReportTask']);

        $create = $this->json('/api/crontabs', [
            'name' => '测试任务',
            'handler' => 'App\\Crontab\\DemoReportTask',
            'rule' => '0 9 * * *',
            'params' => ['title' => '测试日报'],
        ], $headers);
        $create->assertOk();
        $taskId = $create->json('data.id');
        $this->assertNotNull($taskId);
        $this->assertSame('0 9 * * *', $create->json('data.rule'));
        $this->assertNotNull($create->json('data.next_run_at'), '启用中的任务应能计算下次运行时间');

        // 手动触发：同步执行，输出 DemoReportTask 的返回
        $run = $this->json('/api/crontabs/' . $taskId . '/run', [], $headers);
        $run->assertOk();
        $this->assertSame(1, $run->json('data.status'));
        $this->assertSame('成功', $run->json('data.status_text'));
        $this->assertStringContainsString('测试日报', (string) $run->json('data.output'));

        // 执行后 last_run_at 有值、日志可查
        $detail = $this->get('/api/crontabs?page_size=100', [], $headers);
        $found = null;
        foreach ($detail->json('data.items') as $item) {
            if ($item['id'] === $taskId) {
                $found = $item;
            }
        }
        $this->assertNotNull($found, '任务列表应包含新建任务');
        $this->assertNotEmpty($found['last_run_at']);

        $logs = $this->get('/api/crontabs/' . $taskId . '/logs', [], $headers);
        $logs->assertOk();
        $this->assertGreaterThan(0, $logs->json('data.total'));
        $this->assertSame('成功', $logs->json('data.items.0.status_text'));

        // 删除任务连同日志
        $del = $this->delete('/api/crontabs/' . $taskId, [], $headers);
        $del->assertOk();
        $this->assertSame(0, (int) CrontabLog::query()->where('task_id', $taskId)->count());
    }

    public function testRunMissingTaskReturns404(): void
    {
        $token = $this->loginToken('admin', 'admin123456');
        $resp = $this->json('/api/crontabs/999999/run', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(404, $resp->json('code'));
    }

    public function testUpdateTask(): void
    {
        $token = $this->loginToken('admin', 'admin123456');
        $headers = ['Authorization' => 'Bearer ' . $token];
        $this->cleanHandlers(['App\\Crontab\\DemoReportTask']);

        $create = $this->json('/api/crontabs', [
            'name' => '更新前',
            'handler' => 'App\\Crontab\\DemoReportTask',
            'rule' => '0 9 * * *',
        ], $headers);
        $taskId = $create->json('data.id');

        $update = $this->put('/api/crontabs/' . $taskId, [
            'name' => '更新后',
            'handler' => 'App\\Crontab\\DemoReportTask',
            'rule' => '*/30 * * * *',
            'status' => 0,
        ], $headers);
        $update->assertOk();
        $this->assertSame('更新后', $update->json('data.name'));
        $this->assertSame('*/30 * * * *', $update->json('data.rule'));
        $this->assertSame(0, $update->json('data.status'));
        // 停用任务不计算下次运行时间
        $this->assertNull($update->json('data.next_run_at'));

        $this->delete('/api/crontabs/' . $taskId, [], $headers);
    }

    public function testIsDueJudgement(): void
    {
        /** @var ContainerInterface $container */
        $process = make(CrontabProcess::class);

        $task = new \App\Model\CrontabTask();
        $task->rule = '* * * * *';
        $task->created_at = new \DateTimeImmutable('-1 hour');
        $task->last_run_at = null;
        $next = $process->nextRun($task);
        $this->assertNotNull($next, '每分钟任务且从未跑过 → 应到期');

        $task->last_run_at = new \DateTimeImmutable('now');
        $this->assertNull($process->nextRun($task), '刚刚跑过每分钟任务 → 不应到期');

        $task->rule = '0 9 * * *';
        $task->last_run_at = new \DateTimeImmutable('today 09:00');
        $now = new \DateTimeImmutable('today 10:00');
        $this->assertNull($process->nextRun($task, $now), '今天 9 点已跑过，下一次在明天 → 不应到期');

        $task->last_run_at = new \DateTimeImmutable('yesterday 09:00');
        $next2 = $process->nextRun($task, $now);
        $this->assertNotNull($next2, '昨天跑过，今天 9 点那次已过 → 应到期');
        $this->assertSame('09:00', $next2->format('H:i'), '窗口标识为本次应执行的运行时间');

        $task->rule = 'not-a-cron';
        $this->assertNull($process->nextRun($task), '非法表达式永远不到期');
    }

    public function testTaskClassMustImplementTaskInterfaceOnRun(): void
    {
        $token = $this->loginToken('admin', 'admin123456');
        $headers = ['Authorization' => 'Bearer ' . $token];
        $this->cleanHandlers(['App\\Crontab\\TaskInterface']);

        // handler 指向不实现 TaskInterface 的类，触发应记为失败日志
        $create = $this->json('/api/crontabs', [
            'name' => '坏 handler 任务',
            'handler' => 'App\\Crontab\\TaskInterface',
            'rule' => '0 9 * * *',
        ], $headers);
        $taskId = $create->json('data.id');

        $run = $this->json('/api/crontabs/' . $taskId . '/run', [], $headers);
        $run->assertOk();
        $this->assertSame(2, $run->json('data.status'), '不实现 TaskInterface 的 handler 应执行失败');
        $this->assertStringContainsString('TaskInterface', (string) $run->json('data.output'));

        // 连续失败计数 +1
        $this->assertSame(1, (int) \App\Model\CrontabTask::query()->find($taskId)?->consecutive_failures);

        $this->delete('/api/crontabs/' . $taskId, [], $headers);
    }

    public function testSystemLogPruneTask(): void
    {
        // 造一条 91 天前的登录日志与 181 天前的操作日志
        $old = date('Y-m-d H:i:s', time() - 91 * 86400);
        $veryOld = date('Y-m-d H:i:s', time() - 181 * 86400);
        $loginId = \App\Model\LoginLog::query()->insertGetId([
            'user_id' => 0, 'username' => 'prune-test', 'event' => 'logout', 'ip' => '127.0.0.1',
            'user_agent' => '', 'message' => '', 'created_at' => $old,
        ]);
        $opId = \App\Model\OperationLog::query()->insertGetId([
            'user_id' => 0, 'username' => 'prune-test', 'method' => 'POST', 'path' => '/api/prune-test',
            'action' => '', 'params' => null, 'status_code' => 200, 'cost_ms' => 1,
            'ip' => '127.0.0.1', 'user_agent' => '', 'created_at' => $veryOld,
        ]);

        $task = make(\App\Crontab\SystemLogPruneTask::class);
        $output = $task->execute([]);

        $this->assertNull(\App\Model\LoginLog::query()->find($loginId), '91 天前的登录日志应被清理');
        $this->assertNull(\App\Model\OperationLog::query()->find($opId), '181 天前的操作日志应被清理');
        $this->assertStringContainsString('登录日志清理', $output);
    }

    public function testFailAlertThreshold(): void
    {
        $token = $this->loginToken('admin', 'admin123456');
        $headers = ['Authorization' => 'Bearer ' . $token];
        $this->cleanHandlers(['App\\Crontab\\TaskInterface']);

        $create = $this->json('/api/crontabs', [
            'name' => '告警阈值验证',
            'handler' => 'App\\Crontab\\TaskInterface',
            'rule' => '0 9 * * *',
        ], $headers);
        $taskId = $create->json('data.id');

        // 默认阈值 3：连续触发 3 次失败
        for ($i = 0; $i < 3; $i++) {
            $this->json('/api/crontabs/' . $taskId . '/run', [], $headers);
        }
        $task = \App\Model\CrontabTask::query()->find($taskId);
        $this->assertSame(3, (int) $task?->consecutive_failures);

        // 成功一次后清零
        $update = $this->put('/api/crontabs/' . $taskId, [
            'name' => '告警阈值验证',
            'handler' => 'App\\Crontab\\DemoReportTask',
            'rule' => '0 9 * * *',
        ], $headers);
        $update->assertOk();
        $this->json('/api/crontabs/' . $taskId . '/run', [], $headers);
        $this->assertSame(0, (int) \App\Model\CrontabTask::query()->find($taskId)?->consecutive_failures);

        $this->delete('/api/crontabs/' . $taskId, [], $headers);
    }

    private function loginToken(string $username, string $password): string
    {
        $body = $this->json('/api/auth/login', [
            'username' => $username,
            'password' => $password,
        ])->json();
        $this->assertSame(0, $body['code'], '登录应成功：' . ($body['message'] ?? ''));

        return $body['data']['access_token'];
    }
}
