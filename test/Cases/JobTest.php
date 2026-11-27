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

namespace HyperfTest\Cases;

use App\Exception\BusinessException;
use App\Job\BaseJob;
use App\Job\ExportJob;
use App\Model\File;
use App\Model\Job;
use App\Model\LoginLog;
use App\Model\Notification;
use App\Model\User;
use App\Service\JobService;
use App\Service\TokenService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class JobTest extends TestCase
{
    private const USERNAME = 'test_job_user';

    private JobService $jobs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobs = ApplicationContext::getContainer()->get(JobService::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testCreateExportViaApi(): void
    {
        $headers = $this->adminHeaders();

        $resp = $this->json('/api/jobs/export', [
            'type' => Job::TYPE_EXPORT_LOGIN_LOGS,
            'filters' => ['username' => 'admin'],
        ], $headers);

        $this->assertSame(0, $resp['code'], '创建任务应成功');
        $this->assertGreaterThan(0, $resp['data']['id']);
        $this->assertSame(Job::TYPE_EXPORT_LOGIN_LOGS, $resp['data']['type']);
        $this->assertSame('导出登录审计', $resp['data']['type_text']);

        // 非法类型被校验拦截
        $bad = $this->json('/api/jobs/export', ['type' => 'export.nope'], $headers);
        $this->assertNotSame(0, $bad['code']);
    }

    public function testExportJobSucceedsAndRegistersResultFile(): void
    {
        $user = $this->createUser();
        $this->ensureLoginLogExists();

        $record = $this->jobs->dispatchExport(Job::TYPE_EXPORT_LOGIN_LOGS, ['username' => self::USERNAME], (int) $user->id, null);
        $this->assertGreaterThan(0, $record->id);

        // 同步执行（跳过队列）
        (new ExportJob((int) $record->id))->handle();

        $record->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $record->status);
        $this->assertNotNull($record->result_file_id);
        $this->assertNotNull($record->finished_at);
        $this->assertNotNull($record->duration_ms);

        // 结果文件已登记到文件管理
        /** @var null|File $file */
        $file = File::query()->find($record->result_file_id);
        $this->assertNotNull($file);
        $this->assertSame('login-logs.xlsx', $file->name);
        $this->assertSame('xlsx', $file->ext);

        // 提交人收到完成站内消息
        $notified = Notification::query()->where('user_id', $user->id)
            ->where('title', 'like', '%任务完成%')->count();
        $this->assertGreaterThan(0, $notified, '任务完成应有站内通知');
    }

    public function testFailedJobRecordsErrorAndRetryCreatesNewTask(): void
    {
        $user = $this->createUser();

        $record = $this->jobs->dispatchExport(Job::TYPE_EXPORT_LOGIN_LOGS, [], (int) $user->id, null);

        // 用匿名任务类制造失败
        $failing = new class((int) $record->id) extends BaseJob {
            public function run(): void
            {
                throw new RuntimeException('boom');
            }
        };
        $failing->handle();

        $record->refresh();
        $this->assertSame(Job::STATUS_FAILED, $record->status);
        $this->assertSame('boom', $record->error);
        $this->assertNotNull($record->finished_at);

        // 失败任务也应有站内通知
        $notified = Notification::query()->where('user_id', $user->id)
            ->where('title', 'like', '%任务失败%')->count();
        $this->assertGreaterThan(0, $notified);

        // 重试：新建 waiting 任务，原失败记录保留
        $retried = $this->jobs->retry($record);
        $this->assertNotSame($record->id, $retried->id);
        $this->assertSame(Job::STATUS_WAITING, $retried->status);
        $this->assertSame($record->type, $retried->type);
        $this->assertSame($record->user_id, $retried->user_id);

        // 已成功任务不能重试；未结束任务不能删
        $record->refresh();
        $this->expectException(BusinessException::class);
        // 重试成功后：先把新任务跑成功，再对失败任务重试应被拒（失败→重试合法），改测「对 waiting 任务重试」
        $this->jobs->retry($retried);
    }

    public function testSuccessfulTaskCannotBeRetried(): void
    {
        $this->ensureLoginLogExists();
        $record = $this->jobs->dispatchExport(Job::TYPE_EXPORT_LOGIN_LOGS, [], null, null);

        (new ExportJob((int) $record->id))->handle();
        $record->refresh();
        $this->assertSame(Job::STATUS_SUCCEEDED, $record->status);

        $this->expectException(BusinessException::class);
        $this->jobs->retry($record);
    }

    public function testDeleteOnlyFinishedJobs(): void
    {
        $record = $this->jobs->dispatchExport(Job::TYPE_EXPORT_LOGIN_LOGS, [], null, null);

        // 未结束（排队/执行中）不可删
        try {
            $this->jobs->destroy($record);
            $this->fail('waiting 任务不应能删除');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('尚未结束', $e->getMessage());
        }

        // 手动标记完成即可删
        $record->status = Job::STATUS_SUCCEEDED;
        $record->save();
        $this->jobs->destroy($record);

        $this->assertNull(Job::query()->find($record->id));
    }

    public function testVisibilityAndOwnershipProtection(): void
    {
        $user = $this->createUser();
        $this->ensureLoginLogExists();

        // 普通用户建一个任务
        $userToken = $this->loginToken(self::USERNAME, 'pass123456');
        $mine = $this->json('/api/jobs/export', ['type' => Job::TYPE_EXPORT_LOGIN_LOGS], [
            'Authorization' => 'Bearer ' . $userToken,
        ]);
        $this->assertSame(0, $mine['code']);
        $myJobId = (int) $mine['data']['id'];

        // admin 建一个任务
        $adminResp = $this->json('/api/jobs/export', ['type' => Job::TYPE_EXPORT_LOGIN_LOGS], $this->adminHeaders());
        $this->assertSame(0, $adminResp['code']);

        // 普通用户（无 jobs.view）只看得到自己的
        $list = $this->get('/api/jobs', [], ['Authorization' => 'Bearer ' . $userToken]);
        $this->assertSame(0, $list['code']);
        foreach ($list['data']['items'] as $item) {
            $this->assertSame((int) $user->id, $item['user_id'], '普通用户只能看到自己的任务');
        }

        // admin（有 jobs.view）看全部
        $adminList = $this->get('/api/jobs', [], $this->adminHeaders());
        $this->assertSame(0, $adminList['code']);
        $this->assertGreaterThanOrEqual(2, $adminList['data']['total']);

        // 别人的任务不能重试/删除
        $hijack = $this->json('/api/jobs/' . $myJobId . '/retry', [], $this->adminHeaders());
        $this->assertNotSame(0, $hijack['code'], 'admin 也不能重试别人的任务');

        // 进行中数量接口：普通用户视角只算自己的
        $running = $this->get('/api/jobs/running', [], ['Authorization' => 'Bearer ' . $userToken]);
        $this->assertSame(0, $running['code']);
        $this->assertArrayHasKey('count', $running['data']);
    }

    // ---------- helpers ----------

    private function createUser(): User
    {
        return User::query()->create([
            'username' => self::USERNAME,
            'password' => password_hash('pass123456', PASSWORD_BCRYPT),
            'nickname' => '任务测试',
            'email' => '',
            'role' => 'user',
            'status' => 1,
        ]);
    }

    private function ensureLoginLogExists(): void
    {
        LoginLog::query()->create([
            'user_id' => null,
            'username' => self::USERNAME,
            'event' => 'login_success',
            'ip' => '127.0.0.1',
            'user_agent' => 'JobTest',
            'message' => '测试日志',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        $login = $this->json('/api/auth/login', ['username' => 'admin', 'password' => 'admin123456']);

        return ['Authorization' => 'Bearer ' . $login['data']['access_token']];
    }

    private function loginToken(string $username, string $password): string
    {
        $login = $this->json('/api/auth/login', ['username' => $username, 'password' => $password]);
        $this->assertSame(0, $login['code'], '登录应成功：' . ($login['message'] ?? ''));

        return (string) $login['data']['access_token'];
    }

    private function cleanup(): void
    {
        /** @var null|User $user */
        $user = User::query()->where('username', self::USERNAME)->first();
        $uid = $user?->id;

        // 清理测试产生的任务与文件
        $jobIds = Job::query()->where(function ($q) use ($uid) {
            $q->where('type', 'like', 'export.%')->orWhere('user_id', $uid);
        })->pluck('id');
        $fileIds = Job::query()->whereIn('id', $jobIds)->whereNotNull('result_file_id')->pluck('result_file_id');
        File::query()->whereIn('id', $fileIds)->delete();
        Job::query()->whereIn('id', $jobIds)->delete();

        Notification::query()->where('user_id', $uid)->delete();
        LoginLog::query()->where('username', self::USERNAME)->delete();

        if ($user !== null) {
            ApplicationContext::getContainer()->get(TokenService::class)
                ->revokeAllForUser((int) $user->id);
            $user->delete();
        }
    }
}
