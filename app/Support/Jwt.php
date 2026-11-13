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

namespace App\Support;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

use function Hyperf\Config\config;

/**
 * JWT 签发与解析（HS256）。
 *
 * - typ 区分 access / refresh，refresh token 不能直接访问业务接口
 * - fid 标识「一次登录」的凭证家族（token family），刷新时沿用，便于整条链一次性吊销
 * - jti 标识单个 token，用于登出与旋转时的单点吊销
 */
final class Jwt
{
    public const TYPE_ACCESS = 'access';

    public const TYPE_REFRESH = 'refresh';

    /**
     * @return array{access_token: string, token_type: string, expires_in: int, expires_at: string}
     */
    public static function issueAccessToken(int $userId, string $username, string $familyId): array
    {
        $ttl = max(60, (int) config('jwt.ttl', 7200));

        return [
            'access_token' => self::encode(self::payload($userId, $username, self::TYPE_ACCESS, $ttl, $familyId)),
            'token_type' => 'Bearer',
            'expires_in' => $ttl,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ];
    }

    /**
     * @return array{refresh_token: string, refresh_expires_in: int, refresh_expires_at: string}
     */
    public static function issueRefreshToken(int $userId, string $username, string $familyId): array
    {
        $ttl = max(300, (int) config('jwt.refresh_ttl', 604800));

        return [
            'refresh_token' => self::encode(self::payload($userId, $username, self::TYPE_REFRESH, $ttl, $familyId)),
            'refresh_expires_in' => $ttl,
            'refresh_expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ];
    }

    public static function newFamilyId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * @param null|string $expectedType 传入时会校验 token 类型，防止用 refresh token 直接访问接口
     * @return array<string, mixed>
     * @throws Throwable Token 非法、被篡改、类型不符或已过期
     */
    public static function parse(string $token, ?string $expectedType = null): array
    {
        $payload = (array) FirebaseJWT::decode($token, new Key(self::secret(), self::algo()));

        if ($expectedType !== null && ($payload['typ'] ?? null) !== $expectedType) {
            throw new RuntimeException('Token 类型不匹配');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(int $userId, string $username, string $type, int $ttl, string $familyId): array
    {
        $now = time();

        return [
            'iss' => (string) config('jwt.issuer', 'hyperf-skeleton'),
            'typ' => $type,
            'sub' => (string) $userId,
            'name' => $username,
            'fid' => $familyId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return FirebaseJWT::encode($payload, self::secret(), self::algo());
    }

    private static function secret(): string
    {
        $secret = (string) config('jwt.secret', '');
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET 未配置，请在 .env 中设置后重启服务');
        }

        return $secret;
    }

    private static function algo(): string
    {
        return (string) config('jwt.algo', 'HS256');
    }
}
