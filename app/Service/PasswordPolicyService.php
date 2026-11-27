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

/**
 * 密码策略：最小长度、字母+数字组合、不含用户名、弱密码黑名单、有效期。
 *
 * 参数全部来自「系统配置」，运维可以随时调严调松，不用改代码。
 */
class PasswordPolicyService
{
    /** 常见弱密码，大小写不敏感 */
    private const WEAK = [
        '123456', '12345678', '123456789', '1234567890', 'password', 'passw0rd',
        'qwerty', 'abc123', 'a123456', 'admin', 'admin123', 'admin123456', '111111',
        '666666', '888888', 'iloveyou', 'letmein', 'welcome',
    ];

    public function __construct(protected ConfigService $configService)
    {
    }

    /**
     * @throws BusinessException 不符合策略
     */
    public function validate(string $password, string $username = ''): void
    {
        $min = max(6, $this->configService->int('password_min_length', 8));

        if (mb_strlen($password) < $min) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, sprintf('密码长度至少 %d 位', $min));
        }

        if (mb_strlen($password) > 64) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '密码长度不能超过 64 位');
        }

        if ($this->configService->bool('password_require_complexity', true)) {
            $hasLetter = preg_match('/[A-Za-z]/', $password) === 1;
            $hasDigit = preg_match('/\d/', $password) === 1;
            if (! $hasLetter || ! $hasDigit) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, '密码需同时包含字母和数字');
            }
        }

        if ($username !== '' && mb_stripos($password, $username) !== false) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '密码不能包含用户名');
        }

        if (in_array(mb_strtolower($password), self::WEAK, true)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '密码过于常见，请换一个');
        }
    }

    /**
     * 密码是否已过期。expire_days = 0 表示不限制。
     */
    public function isExpired(User $user): bool
    {
        $days = max(0, $this->configService->int('password_expire_days', 0));
        if ($days === 0) {
            return false;
        }

        $changedAt = $user->password_changed_at;
        if ($changedAt === null) {
            // 历史数据没有记录改密时间，按创建时间算
            $changedAt = $user->created_at;
        }

        if ($changedAt === null) {
            return false;
        }

        return $changedAt->getTimestamp() + $days * 86400 < time();
    }

    /**
     * 距离过期还有多少天；未开启有效期返回 null。
     */
    public function daysUntilExpire(User $user): ?int
    {
        $days = max(0, $this->configService->int('password_expire_days', 0));
        if ($days === 0) {
            return null;
        }

        $changedAt = $user->password_changed_at ?? $user->created_at;
        if ($changedAt === null) {
            return null;
        }

        $left = (int) ceil(($changedAt->getTimestamp() + $days * 86400 - time()) / 86400);

        return max(0, $left);
    }
}
