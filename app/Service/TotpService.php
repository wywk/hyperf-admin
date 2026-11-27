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
use App\Model\User;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Redis\Redis;
use Throwable;

/**
 * TOTP 两步验证（RFC 6238，SHA1 / 6 位 / 30 秒），手写实现不引第三方包。
 *
 * 两个 Redis 临时键：
 * - totp:setup:{userId}  → 待确认的密钥（10 分钟内有效）
 * - totp:login:{token}   → 已过密码关、等待动态码的用户（5 分钟内有效，一次性）
 */
class TotpService
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        protected Redis $redis,
        protected StdoutLoggerInterface $logger
    ) {
    }

    public function isEnabled(User $user): bool
    {
        return (int) $user->totp_enabled === 1 && (string) $user->totp_secret !== '';
    }

    /**
     * 生成待绑定的密钥与 otpauth 链接（此时还没写库，用户验证通过才生效）。
     *
     * @return array{secret: string, otpauth_url: string, issuer: string}
     */
    public function startSetup(User $user): array
    {
        $secret = $this->generateSecret();
        $issuer = 'Hyperf Admin';

        try {
            $this->redis->setex($this->setupKey((int) $user->id), 600, $secret);
        } catch (Throwable $e) {
            $this->logger->warning('TOTP 待绑定密钥写入失败：' . $e->getMessage());
        }

        return [
            'secret' => $secret,
            'otpauth_url' => $this->uri($secret, (string) $user->username, $issuer),
            'issuer' => $issuer,
        ];
    }

    public function enable(User $user, string $code): void
    {
        $secret = null;
        try {
            $secret = $this->redis->get($this->setupKey((int) $user->id));
        } catch (Throwable $e) {
            $this->logger->warning('TOTP 待绑定密钥读取失败：' . $e->getMessage());
        }

        if (! is_string($secret) || $secret === '') {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '绑定已过期，请重新生成密钥');
        }

        if (! $this->verify($secret, $code, (int) $user->id)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '动态验证码不正确，请确认手机时间是否准确');
        }

        $user->totp_secret = $secret;
        $user->totp_enabled = 1;
        $user->save();

        $this->forget($this->setupKey((int) $user->id));
    }

    public function disable(User $user, string $code): void
    {
        if (! $this->isEnabled($user)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '当前账号未开启两步验证');
        }

        if (! $this->verify((string) $user->totp_secret, $code, (int) $user->id)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '动态验证码不正确');
        }

        $user->totp_enabled = 0;
        $user->totp_secret = '';
        $user->save();
    }

    /**
     * 密码校验通过后签发一次性登录凭证，前端拿它 + 动态码换正式 token。
     */
    public function issueLoginChallenge(int $userId): string
    {
        $token = bin2hex(random_bytes(24));
        try {
            $this->redis->setex($this->loginKey($token), 300, (string) $userId);
        } catch (Throwable $e) {
            $this->logger->warning('TOTP 登录凭证写入失败：' . $e->getMessage());
        }

        return $token;
    }

    /**
     * 校验动态码并返回用户；凭证一次性，用完即删。
     */
    public function consumeLoginChallenge(string $token, string $code): User
    {
        $token = trim($token);
        if ($token === '') {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '缺少二次验证凭证，请重新登录');
        }

        $key = $this->loginKey($token);
        $raw = null;
        try {
            $raw = $this->redis->get($key);
            $this->redis->del($key);
        } catch (Throwable $e) {
            $this->logger->warning('TOTP 登录凭证读取失败：' . $e->getMessage());
        }

        if (! is_string($raw) || $raw === '') {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '二次验证已超时，请重新登录');
        }

        /** @var null|User $user */
        $user = User::query()->find((int) $raw);
        if ($user === null || ! $user->isEnabled()) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '用户不存在或已被禁用');
        }

        if (! $this->isEnabled($user)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '该账号未开启两步验证');
        }

        if (! $this->verify((string) $user->totp_secret, $code, (int) $user->id)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '动态验证码不正确');
        }

        return $user;
    }

    /**
     * 校验动态码，允许前后各一个时间窗（客户端时钟偏差）。
     * 同一用户同一验证码 90 秒内只接受一次，防止窗口内重放。
     */
    public function verify(string $secret, string $code, int $userId = 0): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        if ($userId > 0) {
            $replayKey = sprintf('totp:used:%d:%s', $userId, $code);
            try {
                if ($this->redis->exists($replayKey)) {
                    return false;
                }
            } catch (Throwable) {
                // 缓存不可用时只做校验，不阻断登录
            }
        }

        $counter = intdiv(time(), self::PERIOD);
        $matched = false;
        for ($i = -1; $i <= 1; ++$i) {
            if (hash_equals($this->codeAt($secret, $counter + $i), $code)) {
                $matched = true;
                break;
            }
        }

        if ($matched && $userId > 0) {
            try {
                $this->redis->setex(sprintf('totp:used:%d:%s', $userId, $code), 90, '1');
            } catch (Throwable) {
                // 忽略
            }
        }

        return $matched;
    }

    public function uri(string $secret, string $account, string $issuer = 'Hyperf Admin'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD
        );
    }

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /**
     * 按当前时间算一个动态码；offset 用于取前/后一个时间窗（测试与排障用）。
     */
    public function code(string $secret, int $offset = 0): string
    {
        return $this->codeAt($secret, intdiv(time(), self::PERIOD) + $offset);
    }

    private function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        if ($key === '') {
            return '';
        }

        $binary = pack('N*', 0) . pack('N*', $counter);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4));
        $number = ($value === false ? 0 : $value[1]) & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        $length = strlen($data);
        for ($i = 0; $i < $length; ++$i) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        if ($secret === '') {
            return '';
        }

        $bits = '';
        $length = strlen($secret);
        for ($i = 0; $i < $length; ++$i) {
            $index = strpos(self::ALPHABET, $secret[$i]);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    private function setupKey(int $userId): string
    {
        return 'totp:setup:' . $userId;
    }

    private function loginKey(string $token): string
    {
        return 'totp:login:' . $token;
    }

    private function forget(string $key): void
    {
        try {
            $this->redis->del($key);
        } catch (Throwable) {
            // 忽略
        }
    }
}
