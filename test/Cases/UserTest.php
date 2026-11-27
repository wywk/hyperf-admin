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
use App\Model\User;
use App\Service\UserService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class UserTest extends TestCase
{
    private const PREFIX = 'test_user_';

    private UserService $users;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = ApplicationContext::getContainer()->get(UserService::class);
        $this->adminId = (int) User::query()->where('username', 'admin')->value('id');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testCreateUserAndRejectDuplicateUsername(): void
    {
        $user = $this->users->create([
            'username' => self::PREFIX . 'a',
            'password' => 'pass123456',
            'nickname' => '测试甲',
            'role' => 'user',
        ]);

        $this->assertSame(self::PREFIX . 'a', $user['username']);
        $this->assertSame(1, $user['status']);
        $this->assertArrayNotHasKey('password', $user);

        $this->expectException(BusinessException::class);
        $this->users->create([
            'username' => self::PREFIX . 'a',
            'password' => 'pass123456',
            'role' => 'user',
        ]);
    }

    public function testUnknownRoleRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->users->create([
            'username' => self::PREFIX . 'badrole',
            'password' => 'pass123456',
            'role' => 'not_exists',
        ]);
    }

    public function testCannotDisableOrDeleteSelf(): void
    {
        try {
            $this->users->updateStatus($this->adminId, 0, $this->adminId);
            $this->fail('停用自己应该被拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('自己', $e->getMessage());
        }

        $this->expectException(BusinessException::class);
        $this->users->delete($this->adminId, $this->adminId);
    }

    public function testCannotDisableLastEnabledAdmin(): void
    {
        // 操作者换成别人，绕开「不能操作自己」这条，专门验证最后管理员保护
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/最后一个/');
        $this->users->updateStatus($this->adminId, 0, $this->adminId + 999);
    }

    public function testResetPasswordKicksSessionsAndAllowsNewPassword(): void
    {
        $this->users->create([
            'username' => self::PREFIX . 'pwd',
            'password' => 'old123456',
            'role' => 'user',
        ]);

        $login = $this->json('/api/auth/login', ['username' => self::PREFIX . 'pwd', 'password' => 'old123456']);
        $this->assertSame(0, $login['code']);
        $token = (string) $login['data']['access_token'];

        $me = $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(0, $me['code']);

        $id = (int) User::query()->where('username', self::PREFIX . 'pwd')->value('id');
        $this->users->resetPassword($id, 'new123456');

        // 旧 token 立即失效
        $after = $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(401, $after['code']);

        // 新密码可登录，旧密码不行
        $relogin = $this->json('/api/auth/login', ['username' => self::PREFIX . 'pwd', 'password' => 'new123456']);
        $this->assertSame(0, $relogin['code']);

        $old = $this->json('/api/auth/login', ['username' => self::PREFIX . 'pwd', 'password' => 'old123456']);
        $this->assertSame(401, $old['code']);
    }

    public function testDisabledUserCannotLogin(): void
    {
        $created = $this->users->create([
            'username' => self::PREFIX . 'off',
            'password' => 'pass123456',
            'role' => 'user',
        ]);

        $this->users->updateStatus((int) $created['id'], 0, $this->adminId);

        $login = $this->json('/api/auth/login', ['username' => self::PREFIX . 'off', 'password' => 'pass123456']);
        $this->assertSame(403, $login['code']);

        // 恢复启用后可以登录
        $this->users->updateStatus((int) $created['id'], 1, $this->adminId);
        $ok = $this->json('/api/auth/login', ['username' => self::PREFIX . 'off', 'password' => 'pass123456']);
        $this->assertSame(0, $ok['code']);
    }

    private function cleanup(): void
    {
        User::query()->where('username', 'like', self::PREFIX . '%')->delete();
    }
}
