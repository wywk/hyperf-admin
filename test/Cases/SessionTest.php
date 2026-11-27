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
use App\Service\SessionService;
use App\Service\TokenService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SessionTest extends TestCase
{
    private const USERNAME = 'test_sess_user';

    private SessionService $sessions;

    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = ApplicationContext::getContainer()->get(SessionService::class);
        $this->tokens = ApplicationContext::getContainer()->get(TokenService::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testLoginRegistersSession(): void
    {
        $token = $this->createUserAndLogin();

        $result = $this->sessions->listSessions(self::USERNAME);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['online']);
        $this->assertSame(self::USERNAME, $result['items'][0]['username']);
        $this->assertTrue($result['items'][0]['online']);

        // 会话页与 auth/me 都能正常访问
        $page = $this->get('/api/sessions?keyword=' . self::USERNAME, [], $this->adminHeaders());
        $this->assertSame(0, $page['code']);

        $me = $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(0, $me['code']);
    }

    public function testKickSessionInvalidatesTokenImmediately(): void
    {
        $token = $this->createUserAndLogin();

        $result = $this->sessions->listSessions(self::USERNAME);
        $item = $result['items'][0];

        $this->sessions->kick($item['user_id'], $item['family_id']);

        $after = $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(401, $after['code']);

        $this->assertSame(0, $this->sessions->listSessions(self::USERNAME)['total']);
    }

    public function testUnknownSessionKickRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->sessions->kick(1, 'deadbeefdeadbeef');
    }

    public function testChangePasswordKeepsCurrentSessionAndKillsOthers(): void
    {
        $first = $this->createUserAndLogin();
        $second = $this->json('/api/auth/login', [
            'username' => self::USERNAME,
            'password' => 'pass123456',
        ])['data']['access_token'];

        $changed = $this->post('/api/auth/password', [
            'old_password' => 'pass123456',
            'new_password' => 'pass654321',
        ], ['Authorization' => 'Bearer ' . $second]);

        $this->assertSame(0, $changed['code']);

        // 当前设备（发起修改的 token）继续可用
        $this->assertSame(0, $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $second])['code']);
        // 另一台设备被踢下线
        $this->assertSame(401, $this->get('/api/auth/me', [], ['Authorization' => 'Bearer ' . $first])['code']);
    }

    public function testWrongOldPasswordRejected(): void
    {
        $token = $this->createUserAndLogin();

        $resp = $this->post('/api/auth/password', [
            'old_password' => 'wrongpass',
            'new_password' => 'pass654321',
        ], ['Authorization' => 'Bearer ' . $token]);

        $this->assertSame(422, $resp['code']);
    }

    private function createUserAndLogin(): string
    {
        User::query()->create([
            'username' => self::USERNAME,
            'password' => password_hash('pass123456', PASSWORD_BCRYPT),
            'nickname' => '会话测试',
            'email' => '',
            'role' => 'user',
            'status' => 1,
        ]);

        $login = $this->json('/api/auth/login', ['username' => self::USERNAME, 'password' => 'pass123456']);
        $this->assertSame(0, $login['code']);

        return (string) $login['data']['access_token'];
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        $login = $this->json('/api/auth/login', ['username' => 'admin', 'password' => 'admin123456']);

        return ['Authorization' => 'Bearer ' . $login['data']['access_token']];
    }

    private function cleanup(): void
    {
        /** @var null|User $user */
        $user = User::query()->where('username', self::USERNAME)->first();
        if ($user !== null) {
            $this->tokens->revokeAllForUser((int) $user->id);
            $user->delete();
        }
    }
}
