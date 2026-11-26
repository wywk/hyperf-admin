<?php

declare(strict_types=1);
/**
 * 系统健康监控测试：权限控制、聚合结构、分位数计算。
 *
 * @internal
 * @coversNothing
 */

namespace HyperfTest\Cases;

use App\Model\OperationLog;
use App\Service\HealthService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

class HealthTest extends TestCase
{
    private HealthService $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->health = ApplicationContext::getContainer()->get(HealthService::class);
    }

    public function testOverviewReturnsAllSections(): void
    {
        $data = $this->health->overview();

        foreach (['database', 'redis', 'runtime', 'queue', 'disk', 'crontab', 'api_stats', 'recent_errors'] as $key) {
            $this->assertArrayHasKey($key, $data, "缺少 {$key} 分区");
        }

        $this->assertArrayNotHasKey('error', $data['database'], '数据库分区不应报错');
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', (string) $data['database']['version']);
        $this->assertSame(PHP_VERSION, $data['runtime']['php']);
        $this->assertArrayHasKey('waiting', $data['queue']);
        $this->assertArrayHasKey('used_percent', $data['disk']);
        $this->assertArrayHasKey('collected_at', $data);
    }

    public function testApiStatsComputesPercentile(): void
    {
        $stats = HealthService::computeStats([
            ['path' => '/api/health-bench-slow', 'cost_ms' => 1000],
            ['path' => '/api/health-bench-fast', 'cost_ms' => 10],
            ['path' => '/api/health-bench-fast', 'cost_ms' => 20],
        ]);

        $this->assertSame(3, $stats['total']);
        // 平均 (1000+10+20)/3 ≈ 343
        $this->assertSame(343, $stats['avg_ms']);
        // P95（升序第 floor(3*0.95)=2 位，即 1000）
        $this->assertSame(1000, $stats['p95_ms']);
        // 最慢路径按平均耗时排序，第一条是 slow
        $this->assertSame('/api/health-bench-slow', $stats['slowest'][0]['path']);
        $this->assertSame(15, $stats['slowest'][1]['avg_ms']);

        // 空数据
        $empty = HealthService::computeStats([]);
        $this->assertSame(0, $empty['total']);
        $this->assertNull($empty['avg_ms']);
    }

    public function testRequiresPermission(): void
    {
        // demo 账号没有 health.view
        $login = $this->json('/api/auth/login', ['username' => 'demo', 'password' => 'demo123456']);
        $this->assertSame(0, $login['code']);
        $headers = ['Authorization' => 'Bearer ' . $login['data']['access_token']];

        $denied = $this->get('/api/health', [], $headers);
        $this->assertSame(403, $denied['code']);

        // admin 放行
        $allowed = $this->get('/api/health', [], $this->adminHeaders());
        $this->assertSame(0, $allowed['code']);
        $this->assertArrayHasKey('database', $allowed['data']);
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        $login = $this->json('/api/auth/login', ['username' => 'admin', 'password' => 'admin123456']);

        return ['Authorization' => 'Bearer ' . $login['data']['access_token']];
    }
}
