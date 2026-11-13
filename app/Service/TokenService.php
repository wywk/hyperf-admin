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

    public function __construct(protected Redis $redis, protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * 签发一对 token。传入 $familyId 时沿用同一家族（refresh 旋转），否则开启新家族（登录）。
     *
     * @return array<string, mixed>
     */
    public function issue(User $user, ?string $familyId = null): array
    {
        $familyId ??= Jwt::newFamilyId();

        return array_merge(
            Jwt::issueAccessToken((int) $user->id, (string) $user->username, $familyId),
            Jwt::issueRefreshToken((int) $user->id, (string) $user->username, $familyId)
        );
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
