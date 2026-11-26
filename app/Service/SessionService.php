<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\User;

/**
 * 在线会话：把 TokenService 登记的会话表与用户信息拼成可展示的列表，并支持踢下线。
 *
 * 「在线」的判定是相对宽松的：只要最近一次请求在 15 分钟内，就算在线
 * （access token 有效期 2 小时，但用户可能只是把页面挂在后台）。
 */
class SessionService
{
    /** 最近活跃多久之内算在线（秒） */
    private const ONLINE_WINDOW = 900;

    public function __construct(protected TokenService $tokenService)
    {
    }

    /**
     * @return array{total: int, online: int, items: array<int, array<string, mixed>>}
     */
    public function listSessions(?string $keyword = null, bool $onlyOnline = false, string $currentFamilyId = ''): array
    {
        $query = User::query()->select(['id', 'username', 'nickname', 'role', 'status']);

        $keyword = trim((string) $keyword);
        if ($keyword !== '') {
            $needle = '%' . $keyword . '%';
            $query->where(static function ($q) use ($needle) {
                $q->where('username', 'like', $needle)->orWhere('nickname', 'like', $needle);
            });
        }

        /** @var array<int, User> $users */
        $users = $query->orderBy('id')->get()->all();

        $now = time();
        $items = [];
        foreach ($users as $user) {
            foreach ($this->tokenService->sessionsOf((int) $user->id) as $familyId => $meta) {
                $lastSeen = (int) ($meta['last_seen'] ?? 0);
                $online = $now - $lastSeen <= self::ONLINE_WINDOW;
                if ($onlyOnline && ! $online) {
                    continue;
                }

                $agent = $this->describeUserAgent((string) ($meta['user_agent'] ?? ''));

                $items[] = [
                    'user_id' => (int) $user->id,
                    'username' => (string) $user->username,
                    'nickname' => (string) $user->nickname,
                    'role' => (string) $user->role,
                    'user_status' => (int) $user->status,
                    'family_id' => $familyId,
                    'ip' => (string) ($meta['ip'] ?? ''),
                    'user_agent' => (string) ($meta['user_agent'] ?? ''),
                    'browser' => $agent['browser'],
                    'os' => $agent['os'],
                    'login_at' => $this->formatTime((int) ($meta['login_at'] ?? 0)),
                    'last_seen' => $this->formatTime($lastSeen),
                    'online' => $online,
                    'is_current' => $currentFamilyId !== '' && $familyId === $currentFamilyId,
                ];
            }
        }

        // 当前会话置顶，其余按最后活跃时间倒序
        usort($items, static function (array $a, array $b): int {
            if ($a['is_current'] !== $b['is_current']) {
                return $a['is_current'] ? -1 : 1;
            }

            return strcmp((string) $b['last_seen'], (string) $a['last_seen']);
        });

        return [
            'total' => count($items),
            'online' => count(array_filter($items, static fn (array $row): bool => (bool) $row['online'])),
            'items' => $items,
        ];
    }

    public function kick(int $userId, string $familyId): void
    {
        $sessions = $this->tokenService->sessionsOf($userId);
        if (! array_key_exists($familyId, $sessions)) {
            throw new BusinessException(ApiCode::NOT_FOUND, '该会话已结束或被踢下线');
        }

        $this->tokenService->kickSession($userId, $familyId);
    }

    /**
     * 踢掉某个用户的全部会话，保留操作者自己的当前会话（如果踢的是自己）。
     */
    public function kickAll(int $userId, string $currentFamilyId = ''): int
    {
        $user = User::query()->find($userId);
        if ($user === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '用户不存在或已被删除');
        }

        return $this->tokenService->revokeAllForUser($userId, $currentFamilyId !== '' ? $currentFamilyId : null);
    }

    /**
     * 极简 UA 识别，够列表显示就行；识别不出就原样截断。
     *
     * @return array{browser: string, os: string}
     */
    public function describeUserAgent(string $userAgent): array
    {
        if ($userAgent === '') {
            return ['browser' => '未知', 'os' => '未知'];
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/'), str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/'), str_contains($userAgent, 'Chromium') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            str_contains($userAgent, 'curl/') => 'curl',
            str_contains($userAgent, 'Postman') => 'Postman',
            default => mb_substr($userAgent, 0, 24),
        };

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Mac OS X'), str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => '未知',
        };

        return ['browser' => $browser, 'os' => $os];
    }

    private function formatTime(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '';
    }
}
