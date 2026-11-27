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

use App\Model\OperationLog;
use Hyperf\Contract\StdoutLoggerInterface;
use Throwable;

use function Hyperf\Config\config;

/**
 * 操作日志：写审计与分页查询。
 * 审计属于旁路能力：任何写入失败都只告警，不能影响业务主流程。
 */
class OperationLogService
{
    /**
     * 请求参数中的敏感字段名（小写比较），写入前整键剔除。
     */
    private const SENSITIVE_KEYS = [
        'password', 'old_password', 'new_password', 'password_confirmation',
        'token', 'access_token', 'refresh_token', 'secret', 'authorization',
    ];

    public function __construct(protected StdoutLoggerInterface $logger, protected ConfigService $configService)
    {
    }

    /**
     * 记录一次写操作。params 中的敏感字段会被剔除，超过 64KB 的载荷整体截断。
     *
     * @param null|array $params 已提取的请求参数（body + query），null 表示无可记参数
     * @param null|array $diff 字段级变更快照 [{table,id,field,before,after}]，由模型事件采集
     */
    public function record(
        int $userId,
        string $username,
        string $method,
        string $path,
        string $action,
        ?array $params,
        int $statusCode,
        int $costMs,
        string $ip,
        string $userAgent,
        ?array $diff = null
    ): void {
        if (! $this->configService->bool('operation_log_enabled', (bool) config('security.operation_log_enabled', true))) {
            return;
        }

        try {
            OperationLog::query()->create([
                'user_id' => $userId,
                'username' => mb_substr($username, 0, 64),
                'method' => mb_substr($method, 0, 10),
                'path' => mb_substr($path, 0, 255),
                'action' => mb_substr($action, 0, 128),
                'params' => $this->sanitize($params),
                'diff' => $diff !== [] && $diff !== null ? array_slice($diff, 0, 100) : null,
                'status_code' => max(0, min(65535, $statusCode)),
                'cost_ms' => max(0, $costMs),
                'ip' => mb_substr($ip, 0, 45),
                'user_agent' => mb_substr($userAgent, 0, 512),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning(sprintf('操作日志写入失败（path=%s）：%s', $path, $e->getMessage()));
        }
    }

    /**
     * 分页查询，供后台排查。
     *
     * @param array{user_id?: int, username?: string, method?: string, path?: string, status_code?: int} $filters
     * @return array{total: int, page: int, page_size: int, items: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters, int $page, int $pageSize, ?array $visibleUserIds = null): array
    {
        $query = OperationLog::query();

        if ($visibleUserIds !== null) {
            $query->whereIn('user_id', $visibleUserIds === [] ? [0] : $visibleUserIds);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['username'])) {
            $query->where('username', $filters['username']);
        }
        if (! empty($filters['method'])) {
            $query->where('method', strtoupper($filters['method']));
        }
        if (! empty($filters['path'])) {
            $query->where('path', 'like', '%' . $filters['path'] . '%');
        }
        if (! empty($filters['status_code'])) {
            $query->where('status_code', $filters['status_code']);
        }

        $total = (int) $query->count();
        $rows = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        $items = [];
        foreach ($rows as $row) {
            /* @var OperationLog $row */
            $items[] = [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'username' => (string) $row->username,
                'method' => (string) $row->method,
                'path' => (string) $row->path,
                'action' => (string) $row->action,
                'params' => $row->params,
                'has_diff' => $row->diff !== null && $row->diff !== [],
                'status_code' => (int) $row->status_code,
                'cost_ms' => (int) $row->cost_ms,
                'ip' => (string) $row->ip,
                'user_agent' => (string) $row->user_agent,
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
     * 详情：返回完整记录（含字段级 diff）。查不到返回 null。
     *
     * @return null|array<string, mixed>
     */
    public function detail(int $id, ?array $visibleUserIds = null): ?array
    {
        $query = OperationLog::query()->where('id', $id);

        if ($visibleUserIds !== null) {
            $query->whereIn('user_id', $visibleUserIds === [] ? [0] : $visibleUserIds);
        }

        $row = $query->first();
        if ($row === null) {
            return null;
        }

        /* @var OperationLog $row */
        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'username' => (string) $row->username,
            'method' => (string) $row->method,
            'path' => (string) $row->path,
            'action' => (string) $row->action,
            'params' => $row->params,
            'diff' => $row->diff,
            'status_code' => (int) $row->status_code,
            'cost_ms' => (int) $row->cost_ms,
            'ip' => (string) $row->ip,
            'user_agent' => (string) $row->user_agent,
            'created_at' => $row->created_at?->toDateTimeString(),
        ];
    }

    /**
     * 导出行（最多 limit 条，防止内存失控；复用分页过滤条件）。
     *
     * @return iterable<int, array<int, string>>
     */
    public function exportRows(array $filters, int $limit = 5000, ?array $visibleUserIds = null): iterable
    {
        $query = OperationLog::query();

        if ($visibleUserIds !== null) {
            $query->whereIn('user_id', $visibleUserIds === [] ? [0] : $visibleUserIds);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['username'])) {
            $query->where('username', $filters['username']);
        }
        if (! empty($filters['method'])) {
            $query->where('method', strtoupper($filters['method']));
        }
        if (! empty($filters['path'])) {
            $query->where('path', 'like', '%' . $filters['path'] . '%');
        }
        if (! empty($filters['status_code'])) {
            $query->where('status_code', $filters['status_code']);
        }

        $rows = $query->orderByDesc('id')->limit($limit)->get();

        foreach ($rows as $row) {
            /* @var OperationLog $row */
            yield [
                (string) $row->id,
                (string) $row->username,
                (string) $row->user_id,
                (string) $row->method,
                (string) $row->path,
                (string) json_encode($row->params, JSON_UNESCAPED_UNICODE),
                (string) $row->status_code,
                (string) $row->cost_ms,
                (string) $row->ip,
                (string) ($row->created_at?->toDateTimeString() ?? ''),
            ];
        }
    }

    /**
     * 递归剔除敏感字段；序列化后超长（>64KB）则整体丢弃记为截断标记。
     */
    private function sanitize(?array $params): ?array
    {
        if ($params === null || $params === []) {
            return null;
        }

        $clean = $this->stripSensitive($params);
        $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || mb_strlen($encoded) > 65536) {
            return ['_truncated' => true];
        }

        return $clean;
    }

    private function stripSensitive(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->stripSensitive($value) : $value;
        }

        return $clean;
    }
}
