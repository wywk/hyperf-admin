<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Controller\Api;

use App\Common\ApiResponse;
use App\Common\ApiCode;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Crontab\CrontabExecutor;
use App\Exception\BusinessException;
use App\Model\CrontabTask;
use App\Service\CrontabService;
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwId;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwPut;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 定时任务接口：任务 CRUD、执行日志查询、手动触发。
 *
 * 路由：/api/crontabs（config/routes.php），需要 crontab.* 权限。
 */
class CrontabController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    #[Inject]
    protected CrontabService $crontabService;

    #[Inject]
    protected CrontabExecutor $executor;

    /**
     * GET /api/crontabs.
     */
    #[SwGet(path: '/api/crontabs', operationId: 'crontabIndex', summary: '定时任务分页', tag: '定时任务', page: true,
        desc: '任务定义与调度状态（最近/下次运行时间、连续失败数）。需要 crontab.view 权限。',
        params: [
            new SwQuery('name', desc: '任务名称（模糊匹配）'),
            new SwQuery('status', type: 'integer', desc: '1=启用 0=停用'),
        ],
        responses: [new SwResp(desc: '分页结果，item 含 name/handler/rule/params/status/consecutive_failures/last_run_at/next_run_at', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
            ]),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('crontab.view');

        $data = $this->validateQuery([
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
            'name' => 'nullable|string|max:64',
            'status' => 'nullable|integer|in:0,1',
        ]);

        $items = $this->crontabService->paginateTasks(
            array_filter([
                'name' => $data['name'] ?? null,
                'status' => $data['status'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''),
            max(1, (int) ($data['page'] ?? 1)),
            min(100, max(1, (int) ($data['page_size'] ?? 20)))
        );

        return ApiResponse::success($items);
    }

    /**
     * POST /api/crontabs.
     */
    #[SwPost(path: '/api/crontabs', operationId: 'crontabStore', summary: '新增定时任务', tag: '定时任务',
        body: new SwBody(props: [
            new SwProp('name', type: 'string', desc: '任务名称'),
            new SwProp('handler', type: 'string', desc: '任务处理类，须实现 App\Crontab\TaskInterface'),
            new SwProp('rule', type: 'string', desc: '标准 cron 表达式（5 位），如 */5 * * * *'),
            new SwProp('params', type: 'object', desc: '任务参数（可选），透传给 execute()'),
            new SwProp('status', type: 'integer', desc: '1=启用（默认）0=停用'),
        ]),
        responses: [new SwResp(desc: '创建后的任务', props: [new SwProp('data', type: 'object')])]
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('crontab.add');

        $data = $this->validatePayload();

        $task = new CrontabTask();
        $task->name = $data['name'];
        $task->handler = $data['handler'];
        $task->rule = $data['rule'];
        $task->params = $data['params'] ?? null;
        $task->status = (int) ($data['status'] ?? CrontabTask::STATUS_ENABLED);
        $task->consecutive_failures = 0;
        $task->save();

        return ApiResponse::success($this->crontabService->formatTask($task), '创建成功');
    }

    /**
     * PUT /api/crontabs/{id}.
     */
    #[SwPut(path: '/api/crontabs/{id}', operationId: 'crontabUpdate', summary: '更新定时任务', tag: '定时任务',
        body: new SwBody(props: [
            new SwProp('name', type: 'string', desc: '任务名称'),
            new SwProp('handler', type: 'string', desc: '任务处理类'),
            new SwProp('rule', type: 'string', desc: 'cron 表达式'),
            new SwProp('params', type: 'object', desc: '任务参数'),
            new SwProp('status', type: 'integer', desc: '1=启用 0=停用'),
        ]),
        params: [new SwId('id', desc: '任务 ID')],
        responses: [new SwResp(desc: '更新后的任务', props: [new SwProp('data', type: 'object')])]
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('crontab.edit');

        $task = CrontabTask::query()->find($id);
        if ($task === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '任务不存在');
        }

        $data = $this->validatePayload();

        $task->name = $data['name'];
        $task->handler = $data['handler'];
        $task->rule = $data['rule'];
        $task->params = $data['params'] ?? null;
        $task->status = (int) ($data['status'] ?? $task->status);
        $task->save();

        return ApiResponse::success($this->crontabService->formatTask($task));
    }

    /**
     * DELETE /api/crontabs/{id}.
     */
    #[SwDelete(path: '/api/crontabs/{id}', operationId: 'crontabDestroy', summary: '删除定时任务', tag: '定时任务',
        desc: '删除任务定义与其执行日志。',
        params: [new SwId('id', desc: '任务 ID')],
        responses: [new SwResp(desc: '空')]
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('crontab.delete');

        $task = CrontabTask::query()->find($id);
        if ($task === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '任务不存在');
        }

        \App\Model\CrontabLog::query()->where('task_id', $task->id)->delete();
        $task->delete();

        return ApiResponse::success(null);
    }

    /**
     * POST /api/crontabs/{id}/run.
     */
    #[SwPost(path: '/api/crontabs/{id}/run', operationId: 'crontabRun', summary: '手动触发执行', tag: '定时任务',
        desc: '绕过调度立即执行一次任务（同步），返回执行日志。需要 crontab.run 权限。',
        params: [new SwId('id', desc: '任务 ID')],
        responses: [new SwResp(desc: '执行日志：status/status_text/output/cost_ms', props: [new SwProp('data', type: 'object')])]
    )]
    public function run(int $id): ResponseInterface
    {
        $this->authorize('crontab.run');

        $task = CrontabTask::query()->find($id);
        if ($task === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '任务不存在');
        }

        $log = $this->executor->run($task);

        return ApiResponse::success([
            'id' => (int) $log->id,
            'task_id' => (int) $log->task_id,
            'status' => (int) $log->status,
            'status_text' => $log->statusText(),
            'output' => (string) $log->output,
            'cost_ms' => (int) $log->cost_ms,
            'created_at' => $log->created_at?->toDateTimeString(),
        ]);
    }

    /**
     * GET /api/crontabs/{id}/logs.
     */
    #[SwGet(path: '/api/crontabs/{id}/logs', operationId: 'crontabLogs', summary: '执行日志分页', tag: '定时任务', page: true,
        desc: '任务的执行流水（含手动触发）。需要 crontab.view 权限。',
        params: [new SwId('id', desc: '任务 ID')],
        responses: [new SwResp(desc: '分页结果，item 含 status/status_text/output/cost_ms/created_at', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
            ]),
        ])]
    )]
    public function logs(int $id): ResponseInterface
    {
        $this->authorize('crontab.view');

        if (CrontabTask::query()->find($id) === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '任务不存在');
        }

        $data = $this->validateQuery([
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
        ]);

        return ApiResponse::success($this->crontabService->paginateLogs(
            $id,
            max(1, (int) ($data['page'] ?? 1)),
            min(100, max(1, (int) ($data['page_size'] ?? 20)))
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateQuery(array $rules): array
    {
        return $this->validatorFactory->make($this->request->all(), $rules, [
            'page.integer' => 'page 必须是整数',
            'page.min' => 'page 最小为 1',
            'page_size.between' => 'page_size 取值范围 1-100',
            'status.in' => 'status 只能为 0（停用）或 1（启用）',
        ])->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(): array
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'name' => 'required|string|max:64',
            'handler' => 'required|string|max:128',
            'rule' => 'required|string|max:64',
            'params' => 'nullable|array',
            'status' => 'nullable|integer|in:0,1',
        ], [
            'name.required' => '任务名称不能为空',
            'handler.required' => '任务处理类不能为空',
            'rule.required' => 'cron 表达式不能为空',
            'rule.string' => 'cron 表达式必须是字符串',
            'params.array' => '任务参数必须是对象',
            'status.in' => 'status 只能为 0（停用）或 1（启用）',
        ])->validate();

        if (! $this->crontabService->isValidRule($data['rule'])) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, 'cron 表达式不合法，请使用标准 5 位表达式，如 */5 * * * *');
        }

        return $data;
    }
}
