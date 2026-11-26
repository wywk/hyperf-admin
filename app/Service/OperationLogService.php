<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
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

    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    /**
     * 记录一次写操作。params 中的敏感字段会被剔除，超过 64KB 的载荷整体截断。
     *
     * @param null|array $params 已提取的请求参数（body + query），null 表示无可记参数
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
        string $userAgent
    ): void {
        if (! (bool) config('security.operation_log_enabled', true)) {
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
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $query = OperationLog::query();

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
     * 导出行（最多 limit 条，防止内存失控；复用分页过滤条件）。
     *
     * @return iterable<int, array<int, string>>
     */
    public function exportRows(array $filters, int $limit = 5000): iterable
    {
        $query = OperationLog::query();

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
