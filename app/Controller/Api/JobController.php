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

namespace App\Controller\Api;

use App\Common\ApiCode;
use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Exception\BusinessException;
use App\Model\Job;
use App\Model\User;
use App\Service\DataScopeService;
use App\Service\JobService;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 任务中心：异步任务（当前为导出）的创建、进度查看、重试与删除。
 *
 * 权限模型：
 * - 有 jobs.view（任务中心菜单）权限的账号看全部任务，否则只看自己提交的
 * - 重试 / 删除仅限本人任务（管理员亦然，避免误动别人正在看的结果）
 */
class JobController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(
        protected JobService $jobService,
        protected DataScopeService $dataScope
    ) {
    }

    /**
     * GET /api/jobs.
     */
    #[SwGet(
        path: '/api/jobs',
        operationId: 'jobIndex',
        summary: '异步任务列表',
        tag: '任务中心',
        page: true,
        desc: '异步任务（导出等）的分页列表。拥有任务中心权限的账号看全部，否则只看自己提交的任务。下载结果用 files 模块的 /api/files/{result_file_id}/download。',
        params: [
            new SwQuery('status', type: 'integer', desc: '0=排队中 1=执行中 2=已完成 3=失败'),
            new SwQuery('type', desc: '任务类型，如 export.login_logs'),
        ],
        responses: [new SwResp(desc: '分页结果', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
            ]),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
            'status' => 'nullable|integer|between:0,3',
            'type' => 'nullable|string|max:64',
        ])->validate();

        $scopeUserId = $this->can('jobs.view') ? null : $this->currentUserId();

        return ApiResponse::success($this->jobService->paginate(
            [
                'status' => $data['status'] ?? '',
                'type' => $data['type'] ?? '',
            ],
            max(1, (int) ($data['page'] ?? 1)),
            min(100, max(1, (int) ($data['page_size'] ?? 20))),
            $scopeUserId
        ));
    }

    /**
     * GET /api/jobs/running.
     */
    #[SwGet(
        path: '/api/jobs/running',
        operationId: 'jobRunningCount',
        summary: '进行中的任务数',
        tag: '任务中心',
        desc: '排队 + 执行中的任务数（仅统计自己的），供顶栏角标轮询。',
        responses: [new SwResp(desc: '数量', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'count', type: 'integer'),
            ]),
        ])]
    )]
    public function running(): ResponseInterface
    {
        return ApiResponse::success([
            'count' => $this->jobService->runningCount($this->currentUserId()),
        ]);
    }

    /**
     * POST /api/jobs/export.
     */
    #[SwPost(
        path: '/api/jobs/export',
        operationId: 'jobCreateExport',
        summary: '创建异步导出任务',
        tag: '任务中心',
        desc: '提交一个异步导出任务（登录审计 / 操作日志 / 商品）。返回任务记录，完成后有站内消息提醒，结果文件在任务中心或文件管理下载。数据权限范围在提交时定格。',
        responses: [new SwResp(desc: '创建的任务记录', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'type', type: 'string'),
                new OA\Property(property: 'status', type: 'integer'),
            ]),
        ])]
    )]
    public function createExport(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'type' => 'required|string|in:' . implode(',', array_keys(Job::TYPE_LABELS)),
            'filters' => 'nullable|array',
        ], [
            'type.required' => '任务类型不能为空',
            'type.in' => '不支持的任务类型',
        ])->validate();

        $user = Context::get('user');
        $visibleUserIds = $user instanceof User ? $this->dataScope->visibleUserIds($user) : null;

        $job = $this->jobService->dispatchExport(
            (string) $data['type'],
            (array) ($data['filters'] ?? []),
            $this->currentUserId(),
            $visibleUserIds
        );

        return ApiResponse::success($this->jobService->formatItem($job), '任务已创建');
    }

    /**
     * POST /api/jobs/{id}/retry.
     */
    #[SwPost(
        path: '/api/jobs/{id}/retry',
        operationId: 'jobRetry',
        summary: '重试失败任务',
        tag: '任务中心',
        desc: '基于失败任务重建一条排队任务并重新入队，原失败记录保留可追溯。仅限本人任务。',
        responses: [new SwResp(desc: '新建的任务记录')]
    )]
    public function retry(int $id): ResponseInterface
    {
        $job = Job::query()->findOrFail($id);
        $this->assertOwner($job);

        $newJob = $this->jobService->retry($job);

        return ApiResponse::success($this->jobService->formatItem($newJob), '已重新排队');
    }

    /**
     * DELETE /api/jobs/{id}.
     */
    #[SwDelete(
        path: '/api/jobs/{id}',
        operationId: 'jobDestroy',
        summary: '删除任务记录',
        tag: '任务中心',
        desc: '删除一条已结束（成功/失败）的任务记录；排队/执行中的任务不可删。仅限本人任务。',
        responses: [new SwResp(desc: '已删除')]
    )]
    public function destroy(int $id): ResponseInterface
    {
        $job = Job::query()->findOrFail($id);
        $this->assertOwner($job);

        $this->jobService->destroy($job);

        return ApiResponse::success(null, '已删除');
    }

    private function assertOwner(Job $job): void
    {
        if ($job->user_id !== null && $job->user_id !== $this->currentUserId()) {
            throw new BusinessException(ApiCode::FORBIDDEN, '只能操作自己提交的任务');
        }
    }

    private function currentUserId(): ?int
    {
        $user = Context::get('user');

        return $user instanceof User ? (int) $user->id : null;
    }
}
