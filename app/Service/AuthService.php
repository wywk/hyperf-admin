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
use App\Model\LoginLog;
use App\Model\User;
use App\Support\Jwt;
use Throwable;

class AuthService
{
    public function __construct(
        protected LoginThrottle $throttle,
        protected TokenService $tokenService,
        protected LoginLogService $audit,
        protected RbacService $rbac,
        protected CaptchaService $captcha,
        protected PasswordPolicyService $passwordPolicy,
        protected TotpService $totpService,
        protected DataScopeService $dataScope,
        protected LoginRiskService $risk
    ) {
    }

    /**
     * 账号密码登录，成功返回 token 对与用户信息。
     *
     * 开启两步验证的账号这里只返回一次性凭证，动态码校验通过后才签发 token。
     *
     * @return array<string, mixed>
     */
    public function login(
        string $username,
        string $password,
        string $ip = '',
        string $userAgent = '',
        ?string $captchaId = null,
        ?string $captchaCode = null
    ): array {
        try {
            $this->throttle->assertNotLocked($ip, $username);
        } catch (BusinessException $e) {
            $this->audit->record(LoginLog::EVENT_LOGIN_LOCKED, $username, $ip, null, $e->getMessage(), $userAgent);

            throw $e;
        }

        // 失败次数到阈值（或配置强制开启）时，先过验证码这关，把自动化撞库挡在密码校验之前
        if ($this->captcha->isRequired($ip, $username)) {
            $this->captcha->verify($captchaId, $captchaCode);
        }

        /** @var null|User $user */
        $user = User::query()->where('username', $username)->first();

        // 用户不存在与密码错误返回同一提示，避免暴露账号是否存在
        if ($user === null || ! password_verify($password, (string) $user->password)) {
            $this->throttle->hit($ip, $username);
            $this->audit->record(
                LoginLog::EVENT_LOGIN_FAILED,
                $username,
                $ip,
                $user?->id !== null ? (int) $user->id : null,
                $user === null ? '账号不存在' : '密码错误',
                $userAgent
            );

            throw new BusinessException(ApiCode::UNAUTHORIZED, '用户名或密码错误');
        }

        if (! $user->isEnabled()) {
            $this->audit->record(LoginLog::EVENT_LOGIN_DISABLED, $username, $ip, (int) $user->id, '账号已禁用', $userAgent);

            throw new BusinessException(ApiCode::FORBIDDEN, '账号已被禁用');
        }

        $this->throttle->clear($ip, $username);

        // 开了两步验证：先不发 token，只给一次性凭证
        if ($this->totpService->isEnabled($user)) {
            return [
                'requires_totp' => true,
                'totp_token' => $this->totpService->issueLoginChallenge((int) $user->id),
                'expires_in' => 300,
            ];
        }

        return $this->finishLogin($user, $ip, $userAgent);
    }

    /**
     * 两步验证第二步：用一次性凭证 + 动态码换正式 token。
     *
     * @return array<string, mixed>
     */
    public function verifyTotpLogin(string $totpToken, string $code, string $ip = '', string $userAgent = ''): array
    {
        $user = $this->totpService->consumeLoginChallenge($totpToken, $code);

        return $this->finishLogin($user, $ip, $userAgent);
    }

    /**
     * @return array<string, mixed>
     */
    private function finishLogin(User $user, string $ip, string $userAgent): array
    {
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save();

        // 登录风控：评估风险等级（中高风险会推安全提醒给本人），结果随审计日志落库
        $risk = $this->risk->assessAndNotify((string) $user->username, (int) $user->id, $ip);

        $this->audit->record(
            LoginLog::EVENT_LOGIN_SUCCESS,
            (string) $user->username,
            $ip,
            (int) $user->id,
            '登录成功',
            $userAgent,
            $risk['level'],
            implode('、', $risk['reasons'])
        );

        return array_merge($this->tokenService->issue($user, null, $ip, $userAgent), ['user' => $this->profile($user)]);
    }

    /**
     * 用 refresh token 换新的一对 token，旧 refresh token 立即失效（旋转）。
     * 若同一个 refresh token 被重复使用，视为凭证泄露：吊销整条凭证链，强制重新登录。
     *
     * @return array<string, mixed>
     */
    public function refresh(string $refreshToken, string $ip = '', string $userAgent = ''): array
    {
        try {
            $payload = Jwt::parse($refreshToken, Jwt::TYPE_REFRESH);
        } catch (Throwable) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '刷新凭证无效或已过期，请重新登录');
        }

        $jti = (string) ($payload['jti'] ?? '');
        $familyId = (string) ($payload['fid'] ?? '');
        $userId = (int) ($payload['sub'] ?? 0);
        $username = (string) ($payload['name'] ?? '');

        if ($this->tokenService->isFamilyRevoked($familyId)) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '登录状态已失效，请重新登录');
        }

        if ($this->tokenService->isRevoked($jti)) {
            // 重放检测：这条 refresh 已经被旋转作废，却再次出现 —— 整条链立即注销
            $this->tokenService->revokeFamily($familyId);
            $this->audit->record(
                LoginLog::EVENT_REFRESH_REPLAY,
                $username,
                $ip,
                $userId > 0 ? $userId : null,
                '刷新凭证重复使用，已吊销该次登录的全部凭证',
                $userAgent
            );

            throw new BusinessException(ApiCode::UNAUTHORIZED, '检测到刷新凭证被重复使用，为保护账号安全已注销本次登录，请重新登录');
        }

        /** @var null|User $user */
        $user = User::query()->find($userId);
        if ($user === null || ! $user->isEnabled()) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '用户不存在或已被禁用');
        }

        // 旋转：旧的 refresh token 立刻作废，防止被重复使用
        $this->tokenService->revoke($jti, (int) ($payload['exp'] ?? 0) - time());

        return array_merge($this->tokenService->issue($user, $familyId, $ip, $userAgent), ['user' => $this->profile($user)]);
    }

    /**
     * 修改自己的密码：校验旧密码，改完后除当前会话外的其他设备全部下线。
     *
     * @param array<string, mixed> $payload 当前请求的 token payload（用来保留当前会话）
     */
    public function changePassword(User $user, string $oldPassword, string $newPassword, array $payload = []): void
    {
        if (! password_verify($oldPassword, (string) $user->password)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '当前密码不正确');
        }

        if (password_verify($newPassword, (string) $user->password)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '新密码不能与当前密码相同');
        }

        $this->passwordPolicy->validate($newPassword, (string) $user->username);

        $user->password = password_hash($newPassword, PASSWORD_BCRYPT);
        $user->password_changed_at = date('Y-m-d H:i:s');
        $user->save();

        // 当前这台设备保持登录，其它设备上的会话立即失效
        $this->tokenService->revokeAllForUser((int) $user->id, (string) ($payload['fid'] ?? '') ?: null);
    }

    /**
     * 退出登录：当前 access token 立即失效，并吊销整条凭证链（refresh token 一并失效）。
     *
     * @param array<string, mixed> $payload
     */
    public function logout(array $payload, string $ip = '', string $userAgent = ''): void
    {
        $this->tokenService->revoke(
            (string) ($payload['jti'] ?? ''),
            (int) ($payload['exp'] ?? 0) - time()
        );
        $this->tokenService->revokeFamily((string) ($payload['fid'] ?? ''));
        // 会话表里也同步移除，否则「在线会话」会残留一条已退出的记录
        $this->tokenService->kickSession((int) ($payload['sub'] ?? 0), (string) ($payload['fid'] ?? ''));

        $this->audit->record(
            LoginLog::EVENT_LOGOUT,
            (string) ($payload['name'] ?? ''),
            $ip,
            (int) ($payload['sub'] ?? 0) ?: null,
            '退出登录',
            $userAgent
        );
    }

    /**
     * 对外暴露的用户信息（不含密码）。
     *
     * @return array<string, mixed>
     */
    public function profile(User $user): array
    {
        $role = (string) ($user->role ?: 'user');
        $scope = $this->dataScope->describe($user);

        return [
            'id' => (int) $user->id,
            'username' => (string) $user->username,
            'nickname' => (string) $user->nickname,
            'email' => (string) $user->email,
            'status' => (int) $user->status,
            'role' => $role,
            'roles' => [$role],
            // 数据权限：前端用它展示「你能看到的数据范围」，也方便排查越权
            'data_scope' => $scope['scope'],
            'data_scope_text' => $scope['scope_text'],
            'dept_id' => $scope['dept_id'],
            'dept_name' => $scope['dept_name'],
            // 按钮码：前端 v-permission / 路由守卫用它判断
            'permissions' => $this->rbac->permissionsForRole($role),
            // 菜单树：前端侧边栏直接渲染它
            'menus' => $this->rbac->menuTreeForRole($role),
            'last_login_at' => $user->last_login_at?->toDateTimeString(),
            'created_at' => $user->created_at?->toDateTimeString(),
            // 两步验证状态：前端「个人中心」用它渲染开关
            'totp_enabled' => $this->totpService->isEnabled($user),
            // 密码有效期：过期后前端顶部会提示改密（不阻断登录）
            'password_expired' => $this->passwordPolicy->isExpired($user),
            'password_expires_in' => $this->passwordPolicy->daysUntilExpire($user),
            'password_changed_at' => $user->password_changed_at?->toDateTimeString(),
        ];
    }
}
