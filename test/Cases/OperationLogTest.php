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
use App\Model\Role;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class OperationLogTest extends TestCase
{
    public function testWriteOperationIsRecorded(): void
    {
        $token = $this->loginToken('admin', 'admin123456');

        $unique = 'oplog-' . uniqid();
        $create = $this->json('/api/roles', [
            'code' => $unique,
            'name' => '操作日志验证角色',
            'description' => '由 OperationLogTest 创建',
        ], ['Authorization' => 'Bearer ' . $token]);
        $create->assertOk();

        $log = OperationLog::query()
            ->where('method', 'POST')
            ->where('path', '/api/roles')
            ->where('username', 'admin')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log, '写操作应产生操作日志');
        $this->assertSame(200, (int) $log->status_code);
        $this->assertGreaterThan(0, (int) $log->user_id);
        $this->assertArrayHasKey('code', $log->params ?? []);
        $this->assertSame($unique, $log->params['code']);
        $this->assertNotEmpty($log->ip);
        $this->assertNotEmpty($log->created_at);

        // 清理测试角色
        Role::query()->where('code', $unique)->delete();
    }

    public function testGetRequestIsNotRecorded(): void
    {
        $token = $this->loginToken('admin', 'admin123456');

        $before = (int) OperationLog::query()->where('method', 'GET')->count();
        $this->get('/api/roles', [], ['Authorization' => 'Bearer ' . $token])->assertOk();
        $after = (int) OperationLog::query()->where('method', 'GET')->count();

        $this->assertSame($before, $after, 'GET 读操作不应记录操作日志');
    }

    public function testSensitiveParamsAreStripped(): void
    {
        $token = $this->loginToken('admin', 'admin123456');

        // 用 query 带 password 与 token，验证写入前被剔除
        $this->post('/api/roles?password=secret&token=abc&nickname=oplog-sanitize', [
            'code' => 'oplog-sanitize-' . uniqid(),
            'name' => '脱敏验证角色',
        ], ['Authorization' => 'Bearer ' . $token]);

        $log = OperationLog::query()
            ->where('path', '/api/roles')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($log);
        $params = json_encode($log->params, JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('secret', $params);
        $this->assertStringNotContainsString('abc', $params);

        if (isset($log->params['code'])) {
            Role::query()->where('code', $log->params['code'])->delete();
        } else {
            Role::query()->where('name', '脱敏验证角色')->delete();
        }
    }

    public function testPaginateFilters(): void
    {
        $token = $this->loginToken('admin', 'admin123456');

        $resp = $this->get('/api/operation-logs?method=POST&page_size=5', [], ['Authorization' => 'Bearer ' . $token]);
        $resp->assertOk();
        $body = $resp->json();
        $this->assertSame(0, $body['code']);
        $this->assertGreaterThan(0, $body['data']['total']);
        $this->assertLessThanOrEqual(5, count($body['data']['items']));
        foreach ($body['data']['items'] as $item) {
            $this->assertSame('POST', $item['method']);
        }

        // path 模糊过滤
        $resp2 = $this->get('/api/operation-logs?path=' . urlencode('/api/roles') . '&page_size=100', [], ['Authorization' => 'Bearer ' . $token]);
        $resp2->assertOk();
        foreach ($resp2->json('data.items') as $item) {
            $this->assertStringContainsString('/api/roles', $item['path']);
        }
    }

    public function testRequiresLogin(): void
    {
        $resp = $this->get('/api/operation-logs');
        $this->assertSame(401, $resp->json('code'));
    }

    public function testRequiresOplogViewPermission(): void
    {
        // demo 是 user 角色，只有 dashboard/profile，无 oplog.view
        $token = $this->loginToken('demo', 'demo123456');
        $resp = $this->get('/api/operation-logs', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(403, $resp->json('code'));
    }

    public function testExportReturnsXlsx(): void
    {
        $token = $this->loginToken('admin', 'admin123456');

        // 确保至少有一条日志
        $this->json('/api/roles', ['code' => 'oplog-export-' . uniqid(), 'name' => '导出验证角色'], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        $resp = $this->get('/api/operation-logs/export?method=POST', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString(
            'attachment; filename="operation-logs.xlsx"',
            $resp->getHeaderLine('Content-Disposition')
        );
        // xlsx 是 zip 容器，魔数 PK\x03\x04
        $this->assertSame("PK\x03\x04", substr((string) $resp->getBody(), 0, 4));

        Role::query()->where('name', '导出验证角色')->delete();
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
