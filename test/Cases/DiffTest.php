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

use App\Model\OperationLog;
use App\Model\User;
use Hyperf\Testing\TestCase;

/**
 * 操作日志字段级变更（diff）：
 * 通过 HTTP 写接口触发，验证「改前 → 改后」快照落库、敏感字段脱敏、
 * 创建/删除的单侧记录、详情接口与可见性。
 *
 * @internal
 * @coversNothing
 */
class DiffTest extends TestCase
{
    private const USERNAME = 'test_diff_user';

    private const PASSWORD = 'DiffTest123456';

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testUpdateRecordsBeforeAndAfter(): void
    {
        $headers = $this->adminHeaders();
        $user = $this->createUser();

        $resp = $this->put('/api/users/' . $user->id, [
            'nickname' => '改后昵称',
            'email' => 'diff@example.com',
            'status' => 1,
            'role' => 'user',
        ], $headers);
        $this->assertSame(0, $resp['code'], '更新应成功：' . ($resp['message'] ?? ''));

        $log = $this->latestLog();
        $this->assertNotNull($log, '应记录操作日志');

        $diff = $this->diffOf($log);
        $this->assertNotEmpty($diff, '更新操作应产生 diff');

        $nickname = $this->fieldOf($diff, 'nickname');
        $this->assertSame('Diff测试用户', $nickname['before'], '改前值应来自模型原始快照');
        $this->assertSame('改后昵称', $nickname['after'], '改后值应为新写入值');
        $this->assertSame('users', $nickname['table']);
    }

    public function testPasswordIsMaskedInDiff(): void
    {
        $headers = $this->adminHeaders();
        $user = $this->createUser();

        $resp = $this->put('/api/users/' . $user->id . '/password', [
            'password' => 'SuperSecret999',
        ], $headers);
        $this->assertSame(0, $resp['code'], '重置密码应成功');

        $recent = OperationLog::query()->where('path', 'like', '/api/users%')->orderByDesc('id')->limit(3)->get();
        foreach ($recent as $r) {
            fwrite(STDERR, sprintf('[diag] #%d path=%s method=%s action=%s status=%d cost=%dms ua=%s diff=%s%s', $r->id, $r->path, $r->method, $r->action, $r->status_code, $r->cost_ms, substr((string) $r->user_agent, 0, 30), var_export($r->diff !== null, true), PHP_EOL));
        }
        $diff = $this->diffOf($this->latestLog());
        $password = $this->fieldOf($diff, 'password');

        $this->assertNotNull($password, 'password 字段应有 diff 记录');
        $this->assertSame('******', $password['before'], '改前应脱敏');
        $this->assertSame('******', $password['after'], '改后应脱敏');
        $this->assertStringNotContainsString('SuperSecret999', (string) json_encode($diff), '明文密码不得出现在 diff 中');
    }

    public function testCreateRecordsAfterOnly(): void
    {
        $headers = $this->adminHeaders();

        $resp = $this->json('/api/users', [
            'username' => self::USERNAME . '_new',
            'password' => self::PASSWORD,
            'nickname' => '新建用户',
            'email' => '',
            'role' => 'user',
            'status' => 1,
        ], $headers);
        $this->assertSame(0, $resp['code'], '建号应成功：' . ($resp['message'] ?? ''));

        $diff = $this->diffOf($this->latestLog());
        $nickname = $this->fieldOf($diff, 'nickname');

        $this->assertNotNull($nickname, '创建操作应有 diff');
        $this->assertNull($nickname['before'], '创建类改前应为 null');
        $this->assertSame('新建用户', $nickname['after']);

        // 清理这条额外账号
        User::query()->where('username', self::USERNAME . '_new')->delete();
    }

    public function testDeleteRecordsBeforeOnly(): void
    {
        $headers = $this->adminHeaders();
        $user = $this->createUser();
        $userId = (int) $user->id;

        $resp = $this->delete('/api/users/' . $userId, [], $headers);
        $this->assertSame(0, $resp['code'], '删除应成功');

        $diff = $this->diffOf($this->latestLog());
        $nickname = $this->fieldOf($diff, 'nickname');

        $this->assertNotNull($nickname, '删除操作应有 diff');
        $this->assertSame('Diff测试用户', $nickname['before'], '删除类应记录删除前的值');
        $this->assertNull($nickname['after'], '删除类改后应为 null');
    }

    public function testDetailApiReturnsDiffAndHonorsScope(): void
    {
        $headers = $this->adminHeaders();
        $user = $this->createUser();

        $upd = $this->put('/api/users/' . $user->id, [
            'nickname' => '详情接口验证',
            'email' => '',
            'status' => 1,
            'role' => 'user',
        ], $headers);
        $this->assertSame(0, $upd['code'], '更新应成功：' . json_encode($upd, JSON_UNESCAPED_UNICODE));

        $log = $this->latestLog();

        // admin 可见
        $resp = $this->get('/api/operation-logs/' . $log->id, [], $headers);
        $this->assertSame(0, $resp['code']);
        $this->assertNotEmpty($resp['data']['diff']);
        $this->assertSame('详情接口验证', $this->fieldOf($resp['data']['diff'], 'nickname')['after']);

        // 普通账号（数据范围=仅本人）看不到别人的日志详情
        $demo = $this->json('/api/auth/login', ['username' => 'demo', 'password' => 'demo123456']);
        $demoHeaders = ['Authorization' => 'Bearer ' . $demo['data']['access_token']];
        $forbidden = $this->get('/api/operation-logs/' . $log->id, [], $demoHeaders);
        $this->assertNotSame(0, $forbidden['code'], '非可见范围应被拦截');

        // 不存在的 ID
        $missing = $this->get('/api/operation-logs/99999999', [], $headers);
        $this->assertNotSame(0, $missing['code']);
    }

    // ---------- helpers ----------

    /**
     * 取最新一条 diff 非空的 /api/users% 日志。
     * （防御：组合执行时测试客户端可能对同一请求重复走中间件，后者 diff 为空）.
     */
    private function latestLog(): ?OperationLog
    {
        return OperationLog::query()->where('path', 'like', '/api/users%')
            ->whereNotNull('diff')
            ->orderByDesc('id')->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function diffOf(?OperationLog $log): array
    {
        $this->assertNotNull($log);
        $this->assertIsArray($log->diff, 'diff 应解析为数组');

        return $log->diff;
    }

    /**
     * @param array<int, array<string, mixed>> $diff
     * @return null|array<string, mixed>
     */
    private function fieldOf(array $diff, string $field): ?array
    {
        foreach ($diff as $entry) {
            if (($entry['field'] ?? '') === $field) {
                return $entry;
            }
        }

        return null;
    }

    private function createUser(): User
    {
        return User::query()->create([
            'username' => self::USERNAME,
            'password' => password_hash(self::PASSWORD, PASSWORD_BCRYPT),
            'nickname' => 'Diff测试用户',
            'email' => '',
            'role' => 'user',
            'status' => 1,
        ]);
    }

    private function adminHeaders(): array
    {
        $login = $this->json('/api/auth/login', ['username' => 'admin', 'password' => 'admin123456']);
        $this->assertSame(0, $login['code'], '管理员登录应成功');

        return ['Authorization' => 'Bearer ' . $login['data']['access_token']];
    }

    private function cleanup(): void
    {
        User::query()->where('username', 'like', self::USERNAME . '%')->delete();
        OperationLog::query()->where('path', 'like', '/api/users%')->delete();
    }
}
