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
use App\Model\User;
use App\Service\DataScopeService;
use App\Service\Excel\ExcelService;
use App\Service\OperationLogService;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwId;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 操作日志查询（需登录 + oplog.view 权限）。非「全部数据」的账号只能看到自己范围内的记录。
 */
class OperationLogController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    #[Inject]
    protected ExcelService $excel;

    public function __construct(
        protected OperationLogService $operationLogService,
        protected DataScopeService $dataScope
    ) {
    }

    /**
     * GET /api/operation-logs.
     */
    #[SwGet(
        path: '/api/operation-logs',
        operationId: 'operationLogIndex',
        summary: '操作日志分页',
        tag: '操作日志',
        page: true,
        desc: '登录用户写操作（POST/PUT/DELETE/PATCH）的审计流水，参数已脱敏（password/token 等敏感字段剔除）。需要 oplog.view 权限。',
        params: [
            new SwQuery('user_id', type: 'integer', desc: '操作人用户 ID'),
            new SwQuery('username', desc: '操作人用户名（精确匹配）'),
            new SwQuery('method', desc: '请求方法 POST/PUT/DELETE/PATCH'),
            new SwQuery('path', desc: '请求路径（模糊匹配）'),
            new SwQuery('status_code', type: 'integer', desc: '响应状态码，如 200/422/500'),
        ],
        responses: [new SwResp(desc: '分页结果：{total, page, page_size, items[]}，item 含 user_id/username/method/path/action/params/status_code/cost_ms/ip/created_at', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
            ]),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('oplog.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
            'user_id' => 'nullable|integer|min:1',
            'username' => 'nullable|string|max:64',
            'method' => 'nullable|string|in:POST,PUT,DELETE,PATCH',
            'path' => 'nullable|string|max:255',
            'status_code' => 'nullable|integer|between:100,599',
        ], [
            'page.integer' => 'page 必须是整数',
            'page.min' => 'page 最小为 1',
            'page_size.between' => 'page_size 取值范围 1-100',
            'method.in' => 'method 只支持 POST/PUT/DELETE/PATCH',
            'status_code.between' => 'status_code 取值范围 100-599',
        ])->validate();

        $filters = array_filter([
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'username' => $data['username'] ?? null,
            'method' => isset($data['method']) ? strtoupper($data['method']) : null,
            'path' => $data['path'] ?? null,
            'status_code' => isset($data['status_code']) ? (int) $data['status_code'] : null,
        ], static fn ($value) => $value !== null && $value !== '');

        return ApiResponse::success($this->operationLogService->paginate(
            $filters,
            max(1, (int) ($data['page'] ?? 1)),
            min(100, max(1, (int) ($data['page_size'] ?? 20))),
            $this->visibleUserIds()
        ));
    }

    /**
     * GET /api/operation-logs/export.
     */
    #[SwGet(
        path: '/api/operation-logs/export',
        operationId: 'operationLogExport',
        summary: '操作日志导出 Excel',
        tag: '操作日志',
        desc: '按当前过滤条件导出 xlsx（最多 5000 条，按 ID 倒序）。需要 oplog.view 权限。',
        params: [
            new SwQuery('user_id', type: 'integer', desc: '操作人用户 ID'),
            new SwQuery('username', desc: '操作人用户名（精确匹配）'),
            new SwQuery('method', desc: '请求方法 POST/PUT/DELETE/PATCH'),
            new SwQuery('path', desc: '请求路径（模糊匹配）'),
            new SwQuery('status_code', type: 'integer', desc: '响应状态码'),
        ],
        responses: [new SwResp(desc: 'xlsx 二进制流（Content-Disposition: attachment）')]
    )]
    public function export(): ResponseInterface
    {
        $this->authorize('oplog.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'user_id' => 'nullable|integer|min:1',
            'username' => 'nullable|string|max:64',
            'method' => 'nullable|string|in:POST,PUT,DELETE,PATCH',
            'path' => 'nullable|string|max:255',
            'status_code' => 'nullable|integer|between:100,599',
        ], [
            'method.in' => 'method 只支持 POST/PUT/DELETE/PATCH',
            'status_code.between' => 'status_code 取值范围 100-599',
        ])->validate();

        $filters = array_filter([
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'username' => $data['username'] ?? null,
            'method' => isset($data['method']) ? strtoupper($data['method']) : null,
            'path' => $data['path'] ?? null,
            'status_code' => isset($data['status_code']) ? (int) $data['status_code'] : null,
        ], static fn ($value) => $value !== null && $value !== '');

        $binary = $this->excel->export('operation-logs.xlsx', [
            'ID', '操作人', '用户 ID', '方法', '路径', '参数(已脱敏)', '状态码', '耗时(ms)', 'IP', '时间',
        ], $this->operationLogService->exportRows($filters, 5000, $this->visibleUserIds()));

        return $this->response->raw($binary)
            ->withHeader('Content-Type', ExcelService::XLSX_MIME)
            ->withHeader('Content-Disposition', 'attachment; filename="operation-logs.xlsx"');
    }

    /**
     * GET /api/operation-logs/{id}.
     */
    #[SwGet(
        path: '/api/operation-logs/{id}',
        operationId: 'operationLogShow',
        summary: '操作日志详情',
        tag: '操作日志',
        desc: '返回完整记录，含字段级变更快照 diff（[{table,id,field,before,after}]，敏感字段已脱敏）。数据范围与列表一致。',
        params: [new SwId()],
        responses: [new SwResp(desc: '日志详情', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(
                    property: 'diff',
                    type: 'array',
                    items: new OA\Items(type: 'object'),
                    nullable: true,
                    description: '字段级变更；创建类仅 after，删除类仅 before'
                ),
            ]),
        ])]
    )]
    public function show(int $id): ResponseInterface
    {
        $this->authorize('oplog.view');

        $detail = $this->operationLogService->detail($id, $this->visibleUserIds());
        if ($detail === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '日志不存在或不在可见范围内');
        }

        return ApiResponse::success($detail);
    }

    /**
     * @return null|array<int, int>
     */
    private function visibleUserIds(): ?array
    {
        $user = Context::get('user');

        return $user instanceof User ? $this->dataScope->visibleUserIds($user) : null;
    }
}
