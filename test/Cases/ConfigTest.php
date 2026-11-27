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
use App\Service\ConfigService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ConfigTest extends TestCase
{
    private ConfigService $configs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configs = ApplicationContext::getContainer()->get(ConfigService::class);
        $this->configs->flushCache();
    }

    protected function tearDown(): void
    {
        $this->configs->flushCache();
        parent::tearDown();
    }

    public function testSeededDefaultsHaveCorrectTypes(): void
    {
        $this->assertSame(5, $this->configs->int('login_max_attempts', 0));
        $this->assertSame(900, $this->configs->int('login_decay_seconds', 0));
        $this->assertTrue($this->configs->bool('login_log_enabled', false));
        $this->assertIsString($this->configs->string('site_name', ''));
    }

    public function testGroupedOutputCarriesDisplayMeta(): void
    {
        $groups = $this->configs->grouped();
        $this->assertNotEmpty($groups);

        $security = null;
        foreach ($groups as $group) {
            if ($group['group'] === 'security') {
                $security = $group;
            }
        }

        $this->assertNotNull($security, '应有 security 分组');
        $this->assertSame('安全策略', $security['name']);
        $this->assertNotEmpty($security['items']);
        $this->assertArrayHasKey('key', $security['items'][0]);
        $this->assertArrayHasKey('type', $security['items'][0]);
    }

    public function testSetManyCastsAndInvalidatesCache(): void
    {
        $original = $this->configs->int('login_max_attempts', 5);

        // 先读一次让缓存热起来
        $this->assertSame($original, $this->configs->all()['login_max_attempts']);

        $this->configs->setMany(['login_max_attempts' => '7', 'login_log_enabled' => false]);

        // 写后立即读到新值（缓存已失效）
        $this->assertSame(7, $this->configs->int('login_max_attempts', 0));
        $this->assertFalse($this->configs->bool('login_log_enabled', true));

        // 还原，避免影响其它用例
        $this->configs->setMany(['login_max_attempts' => $original, 'login_log_enabled' => true]);
        $this->assertSame($original, $this->configs->int('login_max_attempts', 0));
    }

    public function testUnknownKeyRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/未知的配置项/');
        $this->configs->setMany(['not_a_real_key' => 'x']);
    }

    public function testNonNumericValueRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->configs->setMany(['login_max_attempts' => 'abc']);
    }

    public function testEmptyItemsRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->configs->setMany([]);
    }
}
