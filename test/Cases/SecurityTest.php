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
use App\Service\AuthService;
use App\Service\CaptchaService;
use App\Service\ConfigService;
use App\Service\LoginThrottle;
use App\Service\PasswordPolicyService;
use App\Service\TotpService;
use App\Service\UserService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SecurityTest extends TestCase
{
    private const PREFIX = 'test_sec_';

    private const IP = '10.9.9.9';

    private UserService $users;

    private CaptchaService $captcha;

    private TotpService $totp;

    private PasswordPolicyService $policy;

    private AuthService $auth;

    private LoginThrottle $throttle;

    private ConfigService $configs;

    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $container = ApplicationContext::getContainer();
        $this->users = $container->get(UserService::class);
        $this->captcha = $container->get(CaptchaService::class);
        $this->totp = $container->get(TotpService::class);
        $this->policy = $container->get(PasswordPolicyService::class);
        $this->auth = $container->get(AuthService::class);
        $this->throttle = $container->get(LoginThrottle::class);
        $this->configs = $container->get(ConfigService::class);
        $this->redis = $container->get(Redis::class);

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->throttle->clear(self::IP, self::PREFIX . 'totp');
        parent::tearDown();
    }

    public function testPasswordPolicy(): void
    {
        $this->expectExceptionMessageMatches('/字母和数字/');
        $this->policy->validate('abcdefgh');
    }

    public function testPasswordPolicyRejectsShortAndWeak(): void
    {
        try {
            $this->policy->validate('abc123');
            $this->fail('长度不足应被拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('至少', $e->getMessage());
        }

        try {
            $this->policy->validate('admin123456');
            $this->fail('弱密码应被拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('常见', $e->getMessage());
        }

        try {
            $this->policy->validate('secuser123', 'secuser');
            $this->fail('包含用户名应被拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('用户名', $e->getMessage());
        }

        // 合规密码：字母 + 数字，长度够，不含用户名
        $this->policy->validate('Whatever8899', 'secuser');
        $this->assertTrue(true);
    }

    public function testCaptchaIssuedAndOneTime(): void
    {
        $issued = $this->captcha->issue();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $issued['image']);

        $code = (string) $this->redis->get('login:captcha:' . $issued['captcha_id']);
        $this->assertSame(4, strlen($code));

        // 错误码：报错
        try {
            $this->captcha->verify($issued['captcha_id'], 'zzzz');
            $this->fail('错误验证码应被拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('错误', $e->getMessage());
        }

        // 一次性：即使这次填对，上一轮已经作废
        $this->expectException(BusinessException::class);
        $this->captcha->verify($issued['captcha_id'], $code);
    }

    public function testCaptchaAcceptedOnce(): void
    {
        $issued = $this->captcha->issue();
        $code = (string) $this->redis->get('login:captcha:' . $issued['captcha_id']);

        $this->captcha->verify($issued['captcha_id'], $code);
        $this->assertTrue(true);
    }

    public function testCaptchaRequiredAfterFailures(): void
    {
        $username = self::PREFIX . 'captcha';
        $this->throttle->clear(self::IP, $username);
        $this->assertFalse($this->captcha->isRequired(self::IP, $username));

        $this->throttle->hit(self::IP, $username);
        $this->throttle->hit(self::IP, $username);
        $this->throttle->hit(self::IP, $username);

        $this->assertTrue($this->captcha->isRequired(self::IP, $username));
        $this->throttle->clear(self::IP, $username);
    }

    public function testLoginRequiresCaptchaAndBlocksWithoutIt(): void
    {
        $username = self::PREFIX . 'gate';
        $this->users->create([
            'username' => $username,
            'password' => 'Gate123456',
            'role' => 'user',
        ]);

        $this->throttle->hit(self::IP, $username);
        $this->throttle->hit(self::IP, $username);
        $this->throttle->hit(self::IP, $username);

        try {
            $this->auth->login($username, 'Gate123456', self::IP);
            $this->fail('达到阈值后不带验证码应被拒绝');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('验证码', $e->getMessage());
        }

        // 带上正确验证码就能登录成功
        $issued = $this->captcha->issue();
        $code = (string) $this->redis->get('login:captcha:' . $issued['captcha_id']);
        $result = $this->auth->login($username, 'Gate123456', self::IP, 'phpunit', $issued['captcha_id'], $code);
        $this->assertArrayHasKey('access_token', $result);
        // 登录成功会清零失败计数
        $this->assertFalse($this->captcha->isRequired(self::IP, $username));
    }

    public function testTotpSetupEnableLoginAndDisable(): void
    {
        $username = self::PREFIX . 'totp';
        $created = $this->users->create([
            'username' => $username,
            'password' => 'Totp123456',
            'role' => 'user',
        ]);
        $this->throttle->clear(self::IP, $username);

        /** @var User $user */
        $user = User::query()->find((int) $created['id']);

        $setup = $this->totp->startSetup($user);
        $secret = (string) $setup['secret'];
        $this->assertStringStartsWith('otpauth://totp/', (string) $setup['otpauth_url']);

        // 错误的动态码不能开启
        try {
            $this->totp->enable($user, '000000');
            $this->fail('错误动态码不应通过');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('动态验证码', $e->getMessage());
        }

        $this->totp->enable($user, $this->totp->code($secret));
        $user->refresh();
        $this->assertTrue($this->totp->isEnabled($user));

        // 第一次登录只拿到一次性凭证
        $step1 = $this->auth->login($username, 'Totp123456', self::IP);
        $this->assertTrue((bool) ($step1['requires_totp'] ?? false));
        $this->assertArrayNotHasKey('access_token', $step1);

        // 用下一个时间窗的码换正式 token（避开同一窗口内的重放保护）
        $step2 = $this->auth->verifyTotpLogin((string) $step1['totp_token'], $this->totp->code($secret, 1), self::IP);
        $this->assertArrayHasKey('access_token', $step2);
        $this->assertSame($username, $step2['user']['username']);

        // 凭证一次性：同一个 totp_token 不能再换一次
        try {
            $this->auth->verifyTotpLogin((string) $step1['totp_token'], $this->totp->code($secret), self::IP);
            $this->fail('一次性凭证不应能复用');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('超时', $e->getMessage());
        }

        // 关闭时用前一个时间窗的码：0/1 已被用过，-1 仍在允许的容差窗口内且未使用
        $this->totp->disable($user, $this->totp->code($secret, -1));
        $user->refresh();
        $this->assertFalse($this->totp->isEnabled($user));

        // 关闭后普通登录直接返回 token
        $plain = $this->auth->login($username, 'Totp123456', self::IP);
        $this->assertArrayHasKey('access_token', $plain);
    }

    public function testPasswordExpiryByPolicy(): void
    {
        $created = $this->users->create([
            'username' => self::PREFIX . 'expire',
            'password' => 'Expire123456',
            'role' => 'user',
        ]);

        /** @var User $user */
        $user = User::query()->find((int) $created['id']);
        $this->assertFalse($this->policy->isExpired($user));

        $this->configs->setMany(['password_expire_days' => '7']);
        $this->configs->flushCache();

        try {
            $this->assertFalse($this->policy->isExpired($user));
            $this->assertSame(7, $this->policy->daysUntilExpire($user));

            $user->password_changed_at = date('Y-m-d H:i:s', time() - 8 * 86400);
            $user->save();
            $user->refresh();

            $this->assertTrue($this->policy->isExpired($user));
            $this->assertSame(0, $this->policy->daysUntilExpire($user));
        } finally {
            $this->configs->setMany(['password_expire_days' => '0']);
            $this->configs->flushCache();
        }
    }

    private function cleanup(): void
    {
        User::query()->where('username', 'like', self::PREFIX . '%')->delete();
    }
}
