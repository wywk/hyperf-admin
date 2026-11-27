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

use App\Model\LoginLog;
use App\Model\Notification;
use App\Model\User;
use App\Service\ConfigService;
use App\Service\LoginRiskService;
use App\Service\TokenService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RiskTest extends TestCase
{
    private const USERNAME = 'test_risk_user';

    private LoginRiskService $risk;

    private ConfigService $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->risk = ApplicationContext::getContainer()->get(LoginRiskService::class);
        $this->config = ApplicationContext::getContainer()->get(ConfigService::class);
        $this->cleanup();
        // 恢复默认开关
        $this->config->setMany(['risk_enabled' => '1']);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->config->setMany(['risk_enabled' => '1']);
        parent::tearDown();
    }

    public function testFirstLoginAndKnownIpAreNormal(): void
    {
        $user = $this->createUser();

        // 首次登录：无历史，正常
        $first = $this->risk->assess(self::USERNAME, (int) $user->id, '1.2.3.4');
        $this->assertSame(LoginLog::RISK_NORMAL, $first['level'], 'reasons=' . implode(',', $first['reasons']));

        // 落一条历史后，同 IP 再登录：正常
        $this->seedLogin($user->id, '1.2.3.4');
        $again = $this->risk->assess(self::USERNAME, (int) $user->id, '1.2.3.4');
        $this->assertSame(LoginLog::RISK_NORMAL, $again['level']);
    }

    public function testNewIpSameSegmentIsLow(): void
    {
        $user = $this->createUser();
        $this->seedLogin($user->id, '1.2.3.4');

        $result = $this->risk->assess(self::USERNAME, (int) $user->id, '1.2.3.99');
        $this->assertSame(LoginLog::RISK_LOW, $result['level']);
        $this->assertStringContainsString('新 IP', implode('', $result['reasons']));
    }

    public function testUnknownSegmentIsMediumAndNotifiesUser(): void
    {
        $user = $this->createUser();
        $this->seedLogin($user->id, '1.2.3.4');

        $result = $this->risk->assessAndNotify(self::USERNAME, (int) $user->id, '8.8.8.8');
        $this->assertSame(LoginLog::RISK_MEDIUM, $result['level']);
        $this->assertStringContainsString('陌生网段', implode('', $result['reasons']));

        // 中风险推安全提醒
        $notified = Notification::query()->where('user_id', $user->id)
            ->where('type', 'security')
            ->where('title', 'like', '%异常登录%')
            ->count();
        $this->assertGreaterThan(0, $notified, '中风险登录应推送安全提醒');
    }

    public function testOddHourEscalatesLevel(): void
    {
        $user = $this->createUser();
        $this->seedLogin($user->id, '1.2.3.4');

        // 白天陌生网段：中风险
        $day = $this->risk->assess(self::USERNAME, (int) $user->id, '8.8.8.8', '2026-11-26 14:00:00');
        $this->assertSame(LoginLog::RISK_MEDIUM, $day['level']);

        // 同样陌生网段，凌晨 2 点：升级高风险
        $night = $this->risk->assess(self::USERNAME, (int) $user->id, '8.8.8.8', '2026-11-26 02:00:00');
        $this->assertSame(LoginLog::RISK_HIGH, $night['level']);

        // 常用 IP + 异常时段：只做低风险标记
        $oddOnly = $this->risk->assess(self::USERNAME, (int) $user->id, '1.2.3.4', '2026-11-26 02:00:00');
        $this->assertSame(LoginLog::RISK_LOW, $oddOnly['level']);
        $this->assertStringContainsString('异常时段', implode('', $oddOnly['reasons']));
    }

    public function testDisabledByConfig(): void
    {
        $user = $this->createUser();
        $this->seedLogin($user->id, '1.2.3.4');

        $this->config->setMany(['risk_enabled' => '0']);
        $this->assertFalse($this->config->bool('risk_enabled', true), '配置应已关闭');

        $result = $this->risk->assessAndNotify(self::USERNAME, (int) $user->id, '8.8.8.8');
        $this->assertSame(LoginLog::RISK_NORMAL, $result['level']);
        $this->assertSame([], $result['reasons']);
    }

    public function testAuditApiFilterByRisk(): void
    {
        $headers = $this->adminHeaders();

        // 造一条中风险记录
        $user = $this->createUser();
        $this->seedLogin($user->id, '1.2.3.4');
        $risky = $this->risk->assess(self::USERNAME, (int) $user->id, '8.8.8.8');
        $this->seedLogin($user->id, '8.8.8.8', $risky['level'], implode('、', $risky['reasons']));

        // 只看中风险的
        $list = $this->get('/api/auth/login-logs?risk_level=2&username=' . self::USERNAME, [], $headers);
        $this->assertSame(0, $list['code']);
        $this->assertSame(1, $list['data']['total']);
        $item = $list['data']['items'][0];
        $this->assertSame(2, $item['risk_level']);
        $this->assertSame('中风险', $item['risk_text']);
        $this->assertStringContainsString('陌生网段', $item['risk_reasons']);

        // 看正常的：只有低风险之前那条
        $normal = $this->get('/api/auth/login-logs?risk_level=0&username=' . self::USERNAME, [], $headers);
        $this->assertSame(0, $normal['code']);
        $this->assertSame(1, $normal['data']['total']);
    }

    // ---------- helpers ----------

    private function createUser(): User
    {
        return User::query()->create([
            'username' => self::USERNAME,
            'password' => password_hash('pass123456', PASSWORD_BCRYPT),
            'nickname' => '风控测试',
            'email' => '',
            'role' => 'user',
            'status' => 1,
        ]);
    }

    private function seedLogin(int $userId, string $ip, int $riskLevel = 0, string $riskReasons = ''): void
    {
        LoginLog::query()->create([
            'user_id' => $userId,
            'username' => self::USERNAME,
            'event' => LoginLog::EVENT_LOGIN_SUCCESS,
            'ip' => $ip,
            'user_agent' => 'RiskTest',
            'message' => '登录成功',
            'risk_level' => $riskLevel,
            'risk_reasons' => $riskReasons,
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
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

    private function cleanup(): void
    {
        /** @var null|User $user */
        $user = User::query()->where('username', self::USERNAME)->first();
        $uid = $user?->id;

        LoginLog::query()->where('username', self::USERNAME)->delete();
        if ($uid !== null) {
            Notification::query()->where('user_id', $uid)->delete();
        }
        if ($user !== null) {
            ApplicationContext::getContainer()->get(TokenService::class)
                ->revokeAllForUser((int) $user->id);
            $user->delete();
        }
    }
}
