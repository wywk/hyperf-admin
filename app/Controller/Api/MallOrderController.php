<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Controller\Api;

use App\Common\ApiCode;
use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Model\MallOrder;
use App\Service\MallOrderService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * MallOrder CRUD 接口（由代码生成器生成）。
 *
 * 路由：/api/mall_order（config/routes.php 里的 [gen:mall_order] 标记块）。
 * 权限码：mall_order.view/.add/.edit/.delete（生成器已注册到权限树）。
 */
class MallOrderController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected MallOrderService $service)
    {
    }

    #[OA\Get(
        path: '/api/mall_order',
        operationId: 'mall_orderIndex',
        summary: '订单管理列表',
        tags: ['订单管理'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: '页码，默认 1',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'page_size', in: 'query', required: false, description: '每页条数，默认 20',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
            new OA\Parameter(name: 'order_no', in: 'query', required: false, description: '订单号',
                schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'receiver', in: 'query', required: false, description: '收货人',
                schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'phone', in: 'query', required: false, description: '联系电话',
                schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, description: '订单状态',
                schema: new OA\Schema(type: 'string')),
        ],
        responses: [
                        new OA\Response(response: '200', description: '成功'),
            new OA\Response(response: '401', ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: '403', ref: '#/components/responses/Forbidden'),
        ]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('mall_order.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
        ] + $this->searchRules())->validate();

        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($data['page_size'] ?? 20)));

        return ApiResponse::success($this->service->paginate(
            array_filter($this->searchFilters($data), static fn ($v) => $v !== null && $v !== ''),
            $page,
            $pageSize
        ));
    }

    #[OA\Get(
        path: '/api/mall_order/{id}',
        operationId: 'mall_orderShow',
        summary: '订单管理详情',
        tags: ['订单管理'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
                        new OA\Response(response: '200', description: '成功'),
            new OA\Response(response: '401', ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: '403', ref: '#/components/responses/Forbidden'),
            new OA\Response(response: '404', ref: '#/components/responses/NotFound'),
        ]
    )]
    public function show(int $id): ResponseInterface
    {
        $this->authorize('mall_order.view');

        $model = MallOrder::query()->find($id);
        if ($model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success($model);
    }

    #[OA\Post(
        path: '/api/mall_order',
        operationId: 'mall_orderStore',
        summary: '新增订单管理',
        tags: ['订单管理'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'order_no', type: 'string', description: '订单号'),
                    new OA\Property(property: 'receiver', type: 'string', nullable: true, description: '收货人'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, description: '联系电话'),
                    new OA\Property(property: 'amount', type: 'number', description: '金额(元)'),
                    new OA\Property(property: 'status', type: 'string', nullable: true, description: '订单状态'),
                    new OA\Property(property: 'user_id', type: 'number', nullable: true, description: '用户ID'),
                    new OA\Property(property: 'address', type: 'string', nullable: true, description: '收货地址'),
                    new OA\Property(property: 'remark', type: 'string', nullable: true, description: '备注'),
                ]
            )
        ),
        responses: [
                        new OA\Response(response: '200', description: '成功'),
            new OA\Response(response: '401', ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: '403', ref: '#/components/responses/Forbidden'),
            new OA\Response(response: '422', ref: '#/components/responses/ValidationError'),
        ]
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('mall_order.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    #[OA\Put(
        path: '/api/mall_order/{id}',
        operationId: 'mall_orderUpdate',
        summary: '更新订单管理',
        description: '支持部分字段更新（传哪些改哪些）。',
        tags: ['订单管理'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'order_no', type: 'string', description: '订单号'),
                    new OA\Property(property: 'receiver', type: 'string', nullable: true, description: '收货人'),
                    new OA\Property(property: 'phone', type: 'string', nullable: true, description: '联系电话'),
                    new OA\Property(property: 'amount', type: 'number', description: '金额(元)'),
                    new OA\Property(property: 'status', type: 'string', nullable: true, description: '订单状态'),
                    new OA\Property(property: 'user_id', type: 'number', nullable: true, description: '用户ID'),
                    new OA\Property(property: 'address', type: 'string', nullable: true, description: '收货地址'),
                    new OA\Property(property: 'remark', type: 'string', nullable: true, description: '备注'),
                ]
            )
        ),
        responses: [
                        new OA\Response(response: '200', description: '成功'),
            new OA\Response(response: '401', ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: '403', ref: '#/components/responses/Forbidden'),
            new OA\Response(response: '404', ref: '#/components/responses/NotFound'),
            new OA\Response(response: '422', ref: '#/components/responses/ValidationError'),
        ]
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('mall_order.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rulesForUpdate())->validate();
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    #[OA\Delete(
        path: '/api/mall_order/{id}',
        operationId: 'mall_orderDestroy',
        summary: '删除订单管理',
        tags: ['订单管理'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
                        new OA\Response(response: '200', description: '成功'),
            new OA\Response(response: '401', ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: '403', ref: '#/components/responses/Forbidden'),
            new OA\Response(response: '404', ref: '#/components/responses/NotFound'),
        ]
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('mall_order.delete');

        $this->service->delete($id);

        return ApiResponse::success(null, '已删除');
    }

    private function rules(): array
    {
        return [
            'order_no' => 'required|string|max:191',
            'receiver' => 'nullable|string|max:191',
            'phone' => 'nullable|string|max:191',
            'amount' => 'required|numeric',
            'status' => 'nullable|in:0,1,2,3,4',
            'user_id' => 'nullable|numeric',
            'address' => 'nullable|string|max:191',
            'remark' => 'nullable|string|max:2000',
        ];
    }

    /** 更新支持部分字段提交：required 放宽为 nullable，传了才校验 */
    private function rulesForUpdate(): array
    {
        return array_map(
            static fn (string $rule): string => str_replace('required', 'nullable', $rule),
            $this->rules()
        );
    }

    /** 搜索字段的校验规则（生成器按配置展开；这里全部放宽为 nullable） */
    private function searchRules(): array
    {
        $rules = [];
        foreach ($this->searchFieldNames() as $name) {
            $rules[$name] = 'nullable';
        }

        return $rules;
    }

    private function searchFieldNames(): array
    {
        return array_keys($this->searchFilters([]));
    }

    /** 从请求里提取搜索条件 */
    private function searchFilters(array $data): array
    {
        return [
        'order_no' => $data['order_no'] ?? null,
        'receiver' => $data['receiver'] ?? null,
        'phone' => $data['phone'] ?? null,
        'status' => $data['status'] ?? null,
        ];
    }
}
