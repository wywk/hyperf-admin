<?php

declare(strict_types=1);
/**
 * 认证流程测试：登录 / 刷新 / 鉴权中间件。
 *
 * @internal
 * @coversNothing
 */

namespace HyperfTest\Cases;

use Hyperf\Testing\TestCase;

class AuthTest extends TestCase
{
    public function testLoginSuccessReturnsTokenPair(): void
    {
        $resp = $this->json('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin123456',
        ]);
        $resp->assertOk();

        $body = $resp->json();
        $this->assertSame(0, $body['code']);
        $this->assertNotEmpty($body['data']['access_token']);
        $this->assertNotEmpty($body['data']['refresh_token']);
        $this->assertSame('admin', $body['data']['user']['username']);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $resp = $this->json('/api/auth/login', [
            'username' => 'admin',
            'password' => 'definitely-wrong',
        ]);
        $body = $resp->json();
        $this->assertNotSame(0, $body['code']);
        $this->assertArrayNotHasKey('access_token', $body['data'] ?? []);
    }

    public function testProtectedRouteRequiresToken(): void
    {
        $resp = $this->get('/api/auth/me');
        $this->assertSame(401, $resp->json('code'));
    }

    public function testMeReturnsUserRolesPermissionsMenus(): void
    {
        $token = $this->loginToken('admin', 'admin123456');

        $resp = $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $token]);
        $resp->assertOk();

        $body = $resp->json();
        $this->assertSame(0, $body['code']);
        // profile 是平铺结构：用户字段 + roles + permissions + menus
        $this->assertSame('admin', $body['data']['username']);
        $this->assertNotEmpty($body['data']['roles']);
        $this->assertIsArray($body['data']['permissions']);
        $this->assertIsArray($body['data']['menus']);
    }

    public function testRefreshRotatesTokens(): void
    {
        $login = $this->json('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin123456',
        ])->json();

        $resp = $this->json('/api/auth/refresh', [
            'refresh_token' => $login['data']['refresh_token'],
        ]);
        $resp->assertOk();

        $body = $resp->json();
        $this->assertSame(0, $body['code']);
        $this->assertNotEmpty($body['data']['access_token']);
        // 新 token 可用
        $me = $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $body['data']['access_token']]);
        $this->assertSame(0, $me->json('code'));
    }

    public function testInvalidatedOldTokenAfterLogout(): void
    {
        $login = $this->json('/api/auth/login', [
            'username' => 'admin',
            'password' => 'admin123456',
        ])->json();
        $token = $login['data']['access_token'];

        $this->json('/api/auth/logout', [], ['Authorization' => 'Bearer ' . $token]);

        $me = $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(401, $me->json('code'));
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

    public function testLoginLogsExportReturnsXlsx(): void
    {
        $token = $this->loginToken('admin', 'admin123456');

        // 登录本身已产生审计流水，直接导出
        $resp = $this->get('/api/auth/login-logs/export?username=admin', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertStringContainsString(
            'attachment; filename="login-logs.xlsx"',
            $resp->getHeaderLine('Content-Disposition')
        );
        // xlsx 是 zip 容器，魔数 PK\x03\x04
        $this->assertSame("PK\x03\x04", substr((string) $resp->getBody(), 0, 4));

        // 未登录 401
        $anon = $this->get('/api/auth/login-logs/export');
        $this->assertSame(401, $anon->getStatusCode());
    }
}
