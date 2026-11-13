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

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Redis\Redis;
use Throwable;

use function Hyperf\Config\config;

/**
 * 登录失败限流：按 IP + 用户名计数，超过阈值锁定一段时间。
 * Redis 不可用时降级放行并记录告警，避免缓存故障导致无法登录。
 */
class LoginThrottle
{
    public function __construct(protected Redis $redis, protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * @throws BusinessException 已被锁定
     */
    public function assertNotLocked(string $ip, string $username): void
    {
        $key = $this->key($ip, $username);

        try {
            $count = (int) $this->redis->get($key);
        } catch (Throwable $e) {
            $this->logger->warning('登录限流查询失败：' . $e->getMessage());

            return;
        }

        if ($count < $this->maxAttempts()) {
            return;
        }

        $ttl = 0;
        try {
            $ttl = (int) $this->redis->ttl($key);
        } catch (Throwable $e) {
            $this->logger->warning('登录限流 TTL 查询失败：' . $e->getMessage());
        }

        $ttl = $ttl > 0 ? $ttl : $this->decaySeconds();

        throw new BusinessException(
            ApiCode::TOO_MANY_REQUESTS,
            sprintf('登录失败次数过多，请 %d 分钟后再试', max(1, (int) ceil($ttl / 60))),
            ['retry_after' => $ttl]
        );
    }

    /**
     * 记录一次登录失败。
     */
    public function hit(string $ip, string $username): void
    {
        $key = $this->key($ip, $username);

        try {
            $count = (int) $this->redis->incr($key);
            // 首次失败开启窗口；达到阈值时把过期时间重置为完整窗口，保证锁定期够长
            if ($count === 1 || $count >= $this->maxAttempts()) {
                $this->redis->expire($key, $this->decaySeconds());
            }
        } catch (Throwable $e) {
            $this->logger->warning('登录失败计数写入失败：' . $e->getMessage());
        }
    }

    /**
     * 登录成功后清空计数。
     */
    public function clear(string $ip, string $username): void
    {
        try {
            $this->redis->del($this->key($ip, $username));
        } catch (Throwable $e) {
            $this->logger->warning('登录失败计数清理失败：' . $e->getMessage());
        }
    }

    private function key(string $ip, string $username): string
    {
        return sprintf('login:fail:%s:%s', $ip, mb_strtolower($username));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('security.login_throttle.max_attempts', 5));
    }

    private function decaySeconds(): int
    {
        return max(1, (int) config('security.login_throttle.decay_seconds', 900));
    }
}
