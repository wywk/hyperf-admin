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

use App\Model\User;
use App\Support\Jwt;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Redis\Redis;
use Throwable;

use function Hyperf\Config\config;

/**
 * Token 签发与吊销。
 *
 * 吊销分两级：
 * - 单个 token：key = jwt:blacklist:{jti}，用于登出、refresh 旋转
 * - 一次登录的全部凭证：key = jwt:family:revoked:{fid}，用于检测到 refresh 重放时整链注销
 *
 * 所有 key 的 TTL 都不超过对应 token 的生命周期，Redis 异常时降级为放行并告警，
 * 避免缓存故障导致全站无法登录。
 */
class TokenService
{
    private const BLACKLIST_PREFIX = 'jwt:blacklist:';

    private const FAMILY_PREFIX = 'jwt:family:revoked:';

    /** 会话登记表：hash，key = auth:sess:{userId}，field = 家族 ID，value = 会话元信息 JSON */
    private const SESSION_PREFIX = 'auth:sess:';

    /** 最后活跃时间的最小刷新间隔（秒），避免每个请求都写一次 Redis */
    private const SESSION_TOUCH_INTERVAL = 60;

    public function __construct(protected Redis $redis, protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * 签发一对 token。传入 $familyId 时沿用同一家族（refresh 旋转），否则开启新家族（登录）。
     * 同时把这次登录登记到会话表，供「在线会话」页面查看与踢下线。
     *
     * @return array<string, mixed>
     */
    public function issue(User $user, ?string $familyId = null, string $ip = '', string $userAgent = ''): array
    {
        $familyId ??= Jwt::newFamilyId();

        $this->registerSession($user, $familyId, $ip, $userAgent);

        return array_merge(
            Jwt::issueAccessToken((int) $user->id, (string) $user->username, $familyId),
            Jwt::issueRefreshToken((int) $user->id, (string) $user->username, $familyId)
        );
    }

    /**
     * 登记（或续期）一次登录会话。TTL 与 refresh token 一致，凭证过期会话自然消失。
     */
    public function registerSession(User $user, string $familyId, string $ip = '', string $userAgent = ''): void
    {
        if ($familyId === '') {
            return;
        }

        $key = self::SESSION_PREFIX . (int) $user->id;
        $now = time();

        try {
            $existing = $this->redis->hGet($key, $familyId);
            $loginAt = $now;
            if (is_string($existing)) {
                $meta = json_decode($existing, true);
                if (is_array($meta) && isset($meta['login_at'])) {
                    // refresh 旋转时保持首次登录时间不变
                    $loginAt = (int) $meta['login_at'];
                }
            }

            $this->redis->hSet($key, $familyId, (string) json_encode([
                'login_at' => $loginAt,
                'last_seen' => $now,
                'ip' => $ip,
                'user_agent' => mb_substr($userAgent, 0, 255),
            ], JSON_UNESCAPED_UNICODE));
            $this->redis->expire($key, $this->sessionTtl());
        } catch (Throwable $e) {
            // 会话表只是可观测性能力，失败不能影响登录
            $this->logger->warning('登录会话登记失败：' . $e->getMessage());
        }
    }

    /**
     * 刷新最后活跃时间（带节流）。
     */
    public function touchSession(int $userId, string $familyId): void
    {
        if ($userId <= 0 || $familyId === '') {
            return;
        }

        $key = self::SESSION_PREFIX . $userId;

        try {
            $raw = $this->redis->hGet($key, $familyId);
            if (! is_string($raw)) {
                return;
            }

            $meta = json_decode($raw, true);
            if (! is_array($meta)) {
                return;
            }

            $now = time();
            if ($now - (int) ($meta['last_seen'] ?? 0) < self::SESSION_TOUCH_INTERVAL) {
                // 顺手续一下 key 的 TTL（hGet 不会刷新过期时间）
                $this->redis->expire($key, $this->sessionTtl());

                return;
            }

            $meta['last_seen'] = $now;
            $this->redis->hSet($key, $familyId, (string) json_encode($meta, JSON_UNESCAPED_UNICODE));
            $this->redis->expire($key, $this->sessionTtl());
        } catch (Throwable $e) {
            $this->logger->warning('登录会话活跃时间更新失败：' . $e->getMessage());
        }
    }

    /**
     * 某个用户的全部会话（field = 家族 ID）。
     *
     * @return array<string, array<string, mixed>>
     */
    public function sessionsOf(int $userId): array
    {
        try {
            $raw = $this->redis->hGetAll(self::SESSION_PREFIX . $userId);
        } catch (Throwable $e) {
            $this->logger->warning('登录会话读取失败：' . $e->getMessage());

            return [];
        }

        $sessions = [];
        foreach ((array) $raw as $familyId => $value) {
            $meta = json_decode((string) $value, true);
            if (is_array($meta)) {
                $sessions[(string) $familyId] = $meta;
            }
        }

        return $sessions;
    }

    /**
     * 踢掉单个会话：吊销家族 + 从会话表移除。
     */
    public function kickSession(int $userId, string $familyId): void
    {
        if ($familyId === '') {
            return;
        }

        $this->revokeFamily($familyId);

        try {
            $this->redis->hDel(self::SESSION_PREFIX . $userId, $familyId);
        } catch (Throwable $e) {
            $this->logger->warning('登录会话移除失败：' . $e->getMessage());
        }
    }

    /**
     * 踢掉某个用户的全部会话（可保留当前会话）。
     *
     * @return int 实际吊销的会话数
     */
    public function revokeAllForUser(int $userId, ?string $exceptFamilyId = null): int
    {
        $sessions = $this->sessionsOf($userId);
        $count = 0;

        foreach (array_keys($sessions) as $familyId) {
            if ($exceptFamilyId !== null && $familyId === $exceptFamilyId) {
                continue;
            }
            $this->kickSession($userId, $familyId);
            ++$count;
        }

        return $count;
    }

    private function sessionTtl(): int
    {
        return max(300, (int) config('jwt.refresh_ttl', 604800));
    }

    public function revoke(string $jti, int $expiresIn): void
    {
        if ($jti === '' || $expiresIn <= 0) {
            return;
        }

        try {
            $this->redis->setex(self::BLACKLIST_PREFIX . $jti, $expiresIn, '1');
        } catch (Throwable $e) {
            $this->logger->warning('Token 加入黑名单失败：' . $e->getMessage());
        }
    }

    public function isRevoked(string $jti): bool
    {
        if ($jti === '') {
            return false;
        }

        try {
            return (bool) $this->redis->exists(self::BLACKLIST_PREFIX . $jti);
        } catch (Throwable $e) {
            $this->logger->warning('Token 黑名单查询失败：' . $e->getMessage());

            return false;
        }
    }

    /**
     * 注销整条凭证链（该次登录签发的所有 access / refresh token 立即失效）。
     * TTL 取 refresh token 的生命周期，保证比链上任一 token 活得久，又不会永久占内存。
     */
    public function revokeFamily(string $familyId, ?int $ttl = null): void
    {
        if ($familyId === '') {
            return;
        }

        $ttl ??= max(300, (int) config('jwt.refresh_ttl', 604800));

        try {
            $this->redis->setex(self::FAMILY_PREFIX . $familyId, $ttl, '1');
        } catch (Throwable $e) {
            $this->logger->warning('Token 家族加入黑名单失败：' . $e->getMessage());
        }
    }

    public function isFamilyRevoked(string $familyId): bool
    {
        if ($familyId === '') {
            return false;
        }

        try {
            return (bool) $this->redis->exists(self::FAMILY_PREFIX . $familyId);
        } catch (Throwable $e) {
            $this->logger->warning('Token 家族黑名单查询失败：' . $e->getMessage());

            return false;
        }
    }
}
