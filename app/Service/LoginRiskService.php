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

use App\Model\LoginLog;
use App\Model\Notification;
use Hyperf\Contract\StdoutLoggerInterface;
use Throwable;

use function Hyperf\Config\config;

/**
 * 登录风控：登录成功时评估本次登录是否可疑，标记等级与原因。
 *
 * 规则（无需 GeoIP，用登录历史近似）：
 * - 无历史（首次登录）：正常——新账号首登不该报警
 * - IP 出现在近 90 天成功登录历史：正常
 * - 新 IP 但与历史 IP 同 /24 网段：低风险（家庭宽带常换 IP）
 * - 全新 /24 网段：中风险（疑似异地/新环境）
 * - 叠加异常时段（默认 23:00-06:00，可配）：等级 +1（封顶高风险）
 *
 * 中风险及以上会推站内安全提醒给用户本人（推失败不影响登录）。
 */
class LoginRiskService
{
    /** 历史观察窗口（天） */
    private const HISTORY_DAYS = 90;

    public function __construct(
        protected ConfigService $configService,
        protected NotificationService $notifications,
        protected StdoutLoggerInterface $logger
    ) {
    }

    /**
     * 评估并在中风险及以上时通知。返回 [等级, 原因列表]。
     *
     * @return array{level: int, reasons: array<int, string>}
     */
    public function assessAndNotify(string $username, ?int $userId, string $ip, ?string $now = null): array
    {
        if (! $this->configService->bool('risk_enabled', (bool) config('security.risk_enabled', true))) {
            return ['level' => LoginLog::RISK_NORMAL, 'reasons' => []];
        }

        $assessment = $this->assess($username, $userId, $ip, $now);

        // 评估结果写日志（失败只告警，登录主流程不受影响）
        if ($assessment['level'] !== LoginLog::RISK_NORMAL && $userId !== null) {
            $this->notifyIfWorthy($username, $userId, $ip, $assessment);
        }

        return $assessment;
    }

    /**
     * @return array{level: int, reasons: array<int, string>}
     */
    public function assess(string $username, ?int $userId, string $ip, ?string $now = null): array
    {
        $now = $now ?? date('Y-m-d H:i:s');
        $level = LoginLog::RISK_NORMAL;
        $reasons = [];

        if ($userId !== null) {
            $history = $this->recentIps($userId, $now);

            if ($history !== [] && ! in_array($ip, $history, true)) {
                // IP 是新的：看网段
                $segment = $this->segment($ip);
                $knownSegments = array_map($this->segment(...), $history);

                if ($segment !== null && in_array($segment, $knownSegments, true)) {
                    $level = max($level, LoginLog::RISK_LOW);
                    $reasons[] = '新 IP 地址（与常用网段相同）';
                } else {
                    $level = max($level, LoginLog::RISK_MEDIUM);
                    $reasons[] = '来自陌生网段的 IP';
                }
            }
        }

        // 异常时段：等级叠加（封顶高风险）
        if ($this->isOddHour($now)) {
            if ($level !== LoginLog::RISK_NORMAL) {
                $level = min(LoginLog::RISK_HIGH, $level + 1);
                $reasons[] = sprintf('异常时段登录（%s）', $this->oddHourRangeText());
            } else {
                // 时段单独命中只做低风险标记，不打扰
                $level = LoginLog::RISK_LOW;
                $reasons[] = sprintf('异常时段登录（%s）', $this->oddHourRangeText());
            }
        }

        return ['level' => $level, 'reasons' => $reasons];
    }

    /**
     * 该用户近 N 天的成功登录 IP（去重，最多 50 个）。
     *
     * @return array<int, string>
     */
    private function recentIps(int $userId, string $now): array
    {
        try {
            $since = date('Y-m-d H:i:s', strtotime($now) - self::HISTORY_DAYS * 86400);

            return LoginLog::query()
                ->where('user_id', $userId)
                ->where('event', LoginLog::EVENT_LOGIN_SUCCESS)
                ->where('ip', '!=', '')
                ->where('created_at', '>=', $since)
                ->pluck('ip')
                ->unique()
                ->values()
                ->take(50)
                ->all();
        } catch (Throwable $e) {
            $this->logger->warning('风控历史 IP 查询失败：' . $e->getMessage());

            return [];
        }
    }

    /**
     * IPv4 的 /24 网段（如 1.2.3.0）；非 IPv4 返回 null。
     */
    private function segment(string $ip): ?string
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return null;
        }

        return implode('.', [$parts[0], $parts[1], $parts[2], '0']);
    }

    /**
     * 是否处于异常时段。配置 risk_odd_hours = "23-6"（含首含尾），空或非法则不启用。
     */
    private function isOddHour(string $now): bool
    {
        $range = trim((string) $this->configService->get('risk_odd_hours', (string) config('security.risk_odd_hours', '23-6')));
        if ($range === '') {
            return false;
        }

        if (! preg_match('/^(\d{1,2})-(\d{1,2})$/', $range, $m)) {
            return false;
        }

        $start = (int) $m[1];
        $end = (int) $m[2];
        $hour = (int) date('G', strtotime($now));

        return $start <= $end
            ? $hour >= $start && $hour <= $end
            : $hour >= $start || $hour <= $end; // 跨零点
    }

    private function oddHourRangeText(): string
    {
        $range = trim((string) $this->configService->get('risk_odd_hours', (string) config('security.risk_odd_hours', '23-6')));

        return str_replace('-', ':00-', $range) . ':00';
    }

    /**
     * 中风险及以上才推站内通知（低风险只标记，避免打扰）。
     *
     * @param array{level: int, reasons: array<int, string>} $assessment
     */
    private function notifyIfWorthy(string $username, int $userId, string $ip, array $assessment): void
    {
        if ($assessment['level'] < LoginLog::RISK_MEDIUM) {
            return;
        }

        try {
            $this->notifications->push(
                $userId,
                '安全提醒：检测到异常登录',
                sprintf(
                    '账号 %s 于 %s 从 %s 登录，命中：%s。如非本人操作请立即修改密码。',
                    $username,
                    date('Y-m-d H:i'),
                    $ip !== '' ? $ip : '未知 IP',
                    implode('、', $assessment['reasons'])
                ),
                Notification::TYPE_SECURITY,
                Notification::LEVEL_WARNING,
                '/audit'
            );
        } catch (Throwable $e) {
            $this->logger->warning('异常登录通知推送失败：' . $e->getMessage());
        }
    }
}
