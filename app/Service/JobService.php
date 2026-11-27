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
use App\Job\ExportJob;
use App\Model\Job;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Throwable;

/**
 * 异步任务中心：台账（jobs 表）+ 队列消息（Redis）的组合管理。
 *
 * dispatch 时先落台账拿到 id，再把 id 塞进消息体；
 * BaseJob::handle 执行时按 id 反查台账做状态流转。
 * 重试 = 基于 failed 任务重建一条 waiting 台账重新入队，错误历史可追溯。
 */
class JobService
{
    public function __construct(
        protected DriverFactory $driverFactory,
        protected LoginLogService $loginLogService,
        protected OperationLogService $operationLogService,
        protected ProductService $productService,
        protected FileManagerService $fileManager,
        protected Excel\ExcelService $excel,
        protected StdoutLoggerInterface $logger
    ) {
    }

    /**
     * 创建导出任务：支持 登录审计 / 操作日志 / 商品 三类。
     *
     * @param array<string, mixed> $filters 业务筛选条件（原样入 payload，执行时还原）
     * @param null|array<int, int> $visibleUserIds 提交时的数据权限范围（执行时无用户上下文，必须在提交时定格）
     */
    public function dispatchExport(string $type, array $filters, ?int $userId, ?array $visibleUserIds = null): Job
    {
        if (! isset(Job::TYPE_LABELS[$type])) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '不支持的任务类型');
        }

        $record = new Job();
        $record->type = $type;
        $record->status = Job::STATUS_WAITING;
        $record->payload = [
            'filters' => $filters,
            'visible_user_ids' => $visibleUserIds,
        ];
        $record->user_id = $userId;
        $record->save();

        $this->driver()->push(new ExportJob((int) $record->id));

        return $record;
    }

    /**
     * 任务分页。userId 传值时只看自己（普通用户），null 时看全部（管理员）。
     *
     * @param array{status?: int, type?: string} $filters
     * @return array{total: int, page: int, page_size: int, items: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters, int $page, int $pageSize, ?int $userId = null): array
    {
        $query = Job::query();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }
        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        $total = (int) $query->count();
        $rows = $query->orderByDesc('id')->forPage($page, $pageSize)->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->formatItem($row);
        }

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $items,
        ];
    }

    /**
     * 进行中的任务数（排队 + 执行中），给顶栏角标轮询用。
     */
    public function runningCount(?int $userId = null): int
    {
        $query = Job::query()->whereIn('status', [Job::STATUS_WAITING, Job::STATUS_EXECUTING]);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return (int) $query->count();
    }

    /**
     * 重试失败任务：重建一条 waiting 台账并重新入队（原任务保留作失败记录）。
     */
    public function retry(Job $job): Job
    {
        if ($job->status !== Job::STATUS_FAILED) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '只有失败的任务可以重试');
        }

        $payload = $job->payload ?? [];

        $record = new Job();
        $record->type = $job->type;
        $record->status = Job::STATUS_WAITING;
        $record->payload = $payload;
        $record->user_id = $job->user_id;
        $record->save();

        $this->driver()->push(new ExportJob((int) $record->id));

        return $record;
    }

    /**
     * 删除任务记录：仅允许删除已结束（成功/失败）的任务，排队中的任务无法从队列撤回，禁止删。
     */
    public function destroy(Job $job): void
    {
        if (in_array($job->status, [Job::STATUS_WAITING, Job::STATUS_EXECUTING], true)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '任务尚未结束，不能删除');
        }

        $job->delete();
    }

    public function formatItem(Job $job): array
    {
        return [
            'id' => (int) $job->id,
            'type' => (string) $job->type,
            'type_text' => Job::TYPE_LABELS[$job->type] ?? $job->type,
            'status' => (int) $job->status,
            'attempts' => (int) $job->attempts,
            'error' => (string) $job->error,
            'result_file_id' => $job->result_file_id,
            'user_id' => $job->user_id,
            'duration_ms' => $job->duration_ms,
            'created_at' => $job->created_at?->toDateTimeString(),
            'finished_at' => $job->finished_at?->toDateTimeString(),
        ];
    }

    /**
     * 执行导出（由 ExportJob 调用）：生成 xlsx 二进制并登记到文件管理，返回文件 ID。
     */
    public function executeExport(Job $record): int
    {
        $payload = $record->payload ?? [];
        $filters = $payload['filters'] ?? [];
        $visibleUserIds = $payload['visible_user_ids'] ?? null;

        [$filename, $headers, $rows] = match ($record->type) {
            Job::TYPE_EXPORT_LOGIN_LOGS => [
                'login-logs.xlsx',
                ['ID', '时间', '用户名', '事件', 'IP', 'User-Agent'],
                $this->loginLogService->exportRows($filters, $visibleUserIds),
            ],
            Job::TYPE_EXPORT_OPERATION_LOGS => [
                'operation-logs.xlsx',
                ['ID', '操作人', '用户 ID', '方法', '路径', '参数(已脱敏)', '状态码', '耗时(ms)', 'IP', '时间'],
                $this->operationLogService->exportRows($filters, 5000, $visibleUserIds),
            ],
            Job::TYPE_EXPORT_PRODUCTS => [
                'products.xlsx',
                ['name' => '名称', 'price' => '价格', 'stock' => '库存', 'status' => '状态'],
                $this->productService->exportRows($filters),
            ],
            default => throw new BusinessException(ApiCode::VALIDATION_FAILED, '不支持的任务类型'),
        };

        $binary = $this->excel->export($filename, $headers, $rows);
        $file = $this->fileManager->upload($binary, $filename, Excel\ExcelService::XLSX_MIME, (int) $record->user_id);

        return (int) $file->id;
    }

    /**
     * 供测试/手动消费：从队列取一条消息并执行。
     *
     * @return bool 是否消费到了消息
     */
    public function consumeOne(): bool
    {
        try {
            $package = $this->driver()->pop();
        } catch (Throwable $e) {
            $this->logger->warning('任务队列读取失败：' . $e->getMessage());

            return false;
        }

        if ($package === null) {
            return false;
        }

        $package->getJob()->handle();

        return true;
    }

    protected function driver(): DriverInterface
    {
        return $this->driverFactory->get('default');
    }
}
