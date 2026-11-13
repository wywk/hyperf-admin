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
        protected RbacService $rbac
    ) {
    }

    /**
     * 账号密码登录，成功返回 token 对与用户信息。
     *
     * @return array<string, mixed>
     */
    public function login(string $username, string $password, string $ip = '', string $userAgent = ''): array
    {
        try {
            $this->throttle->assertNotLocked($ip, $username);
        } catch (BusinessException $e) {
            $this->audit->record(LoginLog::EVENT_LOGIN_LOCKED, $username, $ip, null, $e->getMessage(), $userAgent);

            throw $e;
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

        $user->last_login_at = date('Y-m-d H:i:s');
        $user->save();

        $this->audit->record(LoginLog::EVENT_LOGIN_SUCCESS, $username, $ip, (int) $user->id, '登录成功', $userAgent);

        return array_merge($this->tokenService->issue($user), ['user' => $this->profile($user)]);
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

        return array_merge($this->tokenService->issue($user, $familyId), ['user' => $this->profile($user)]);
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

        return [
            'id' => (int) $user->id,
            'username' => (string) $user->username,
            'nickname' => (string) $user->nickname,
            'email' => (string) $user->email,
            'status' => (int) $user->status,
            'role' => $role,
            'roles' => [$role],
            // 按钮码：前端 v-permission / 路由守卫用它判断
            'permissions' => $this->rbac->permissionsForRole($role),
            // 菜单树：前端侧边栏直接渲染它
            'menus' => $this->rbac->menuTreeForRole($role),
            'last_login_at' => $user->last_login_at?->toDateTimeString(),
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
    }
}
