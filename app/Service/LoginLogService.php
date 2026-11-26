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
use Hyperf\Contract\StdoutLoggerInterface;
use Throwable;

use function Hyperf\Config\config;

/**
 * 写登录审计日志。
 * 审计属于旁路能力：任何写入失败（表不存在、数据库抖动）都只告警，不能影响登录主流程。
 */
class LoginLogService
{
    public function __construct(protected StdoutLoggerInterface $logger, protected ConfigService $configService)
    {
    }

    /**
     * @param null|int $userId 账号不存在时传 null
     * @param int $riskLevel 登录风控等级（仅登录成功事件有值）
     * @param string $riskReasons 风控命中原因（顿号分隔）
     */
    public function record(
        string $event,
        string $username,
        string $ip,
        ?int $userId = null,
        string $message = '',
        string $userAgent = '',
        int $riskLevel = 0,
        string $riskReasons = ''
    ): void {
        if (! $this->configService->bool('login_log_enabled', (bool) config('security.login_log_enabled', true))) {
            return;
        }

        try {
            LoginLog::query()->create([
                'user_id' => $userId,
                'username' => mb_substr($username, 0, 64),
                'event' => $event,
                'ip' => mb_substr($ip, 0, 45),
                'user_agent' => mb_substr($userAgent, 0, 512),
                'message' => mb_substr($message, 0, 255),
                'risk_level' => $riskLevel,
                'risk_reasons' => mb_substr($riskReasons, 0, 255),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf('登录审计日志写入失败（event=%s）：%s', $event, $e->getMessage()));
        }
    }

    /**
     * 分页查询，供后台排查。
     *
     * @param array{event?: string, username?: string, user_id?: int, ip?: string} $filters
     * @param null|array<int, int> $visibleUserIds 数据权限：null=不限；数组=只看这些用户（含空数组=什么都看不到）
     * @return array{total: int, page: int, page_size: int, items: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters, int $page, int $pageSize, ?array $visibleUserIds = null): array
    {
        $query = $this->filteredQuery($filters, $visibleUserIds);

        $total = (int) $query->count();
        $rows = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        $items = [];
        foreach ($rows as $row) {
            /* @var LoginLog $row */
            $items[] = [
                'id' => (int) $row->id,
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'username' => (string) $row->username,
                'event' => (string) $row->event,
                'event_text' => $row->eventText(),
                'ip' => (string) $row->ip,
                'user_agent' => (string) $row->user_agent,
                'message' => (string) $row->message,
                'risk_level' => (int) $row->risk_level,
                'risk_text' => $row->riskText(),
                'risk_reasons' => (string) $row->risk_reasons,
                'created_at' => $row->created_at?->toDateTimeString(),
            ];
        }

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }

    /**
     * 导出全量：与列表共用筛选条件，按 id 升序每批 500 条产出（Generator，内存友好），上限 5000 行。
     *
     * @param array{event?: string, username?: string, user_id?: int, ip?: string} $filters
     * @return \Generator<int, array<string, null|string>>
     */
    public function exportRows(array $filters, ?array $visibleUserIds = null): \Generator
    {
        $rows = $this->filteredQuery($filters, $visibleUserIds)->orderBy('id')->limit(5000)->get();

        foreach ($rows as $row) {
            /* @var LoginLog $row */
            yield [
                'id' => (int) $row->id,
                'created_at' => $row->created_at?->toDateTimeString(),
                'username' => (string) $row->username,
                'event_text' => $row->eventText(),
                'ip' => (string) $row->ip,
                'user_agent' => (string) $row->user_agent,
            ];
        }
    }

    /**
     * 列表/导出共用的筛选查询。
     *
     * @param null|array<int, int> $visibleUserIds 数据权限：null=不限；数组=只看这些用户（含空数组=什么都看不到）
     */
    private function filteredQuery(array $filters, ?array $visibleUserIds = null): \Hyperf\Database\Model\Builder
    {
        $query = LoginLog::query();

        if ($visibleUserIds !== null) {
            $query->whereIn('user_id', $visibleUserIds === [] ? [0] : $visibleUserIds);
        }

        if (! empty($filters['event'])) {
            $query->where('event', $filters['event']);
        }
        if (! empty($filters['username'])) {
            $query->where('username', $filters['username']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['ip'])) {
            $query->where('ip', $filters['ip']);
        }
        if (isset($filters['risk_level']) && $filters['risk_level'] !== '') {
            $query->where('risk_level', (int) $filters['risk_level']);
        }

        return $query;
    }
}
