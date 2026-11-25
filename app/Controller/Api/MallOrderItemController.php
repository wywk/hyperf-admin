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
use App\Model\MallOrderItem;
use App\Service\MallOrderItemService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * MallOrderItem CRUD 接口（由代码生成器生成）。
 *
 * 路由：/api/mall_order_item（config/routes.php 里的 [gen:mall_order_item] 标记块）。
 * 权限码：mall_order_item.view/.add/.edit/.delete（生成器已注册到权限树）。
 */
class MallOrderItemController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected MallOrderItemService $service)
    {
    }

    #[OA\Get(
        path: '/api/mall_order_item',
        operationId: 'mall_order_itemIndex',
        summary: '订单明细列表',
        tags: ['订单明细'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: '页码，默认 1',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'page_size', in: 'query', required: false, description: '每页条数，默认 20',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
            new OA\Parameter(name: 'order_id', in: 'query', required: false, description: '订单ID',
                schema: new OA\Schema(type: 'number')),
            new OA\Parameter(name: 'goods_name', in: 'query', required: false, description: '商品名称',
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
        $this->authorize('mall_order_item.view');

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
        path: '/api/mall_order_item/{id}',
        operationId: 'mall_order_itemShow',
        summary: '订单明细详情',
        tags: ['订单明细'],
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
        $this->authorize('mall_order_item.view');

        $model = MallOrderItem::query()->find($id);
        if ($model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success($model);
    }

    #[OA\Post(
        path: '/api/mall_order_item',
        operationId: 'mall_order_itemStore',
        summary: '新增订单明细',
        tags: ['订单明细'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'order_id', type: 'number', description: '订单ID'),
                    new OA\Property(property: 'goods_id', type: 'number', nullable: true, description: '商品ID'),
                    new OA\Property(property: 'goods_name', type: 'string', description: '商品名称'),
                    new OA\Property(property: 'price', type: 'number', description: '单价(元)'),
                    new OA\Property(property: 'quantity', type: 'number', description: '数量'),
                    new OA\Property(property: 'amount', type: 'number', description: '小计(元)'),
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
        $this->authorize('mall_order_item.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    #[OA\Put(
        path: '/api/mall_order_item/{id}',
        operationId: 'mall_order_itemUpdate',
        summary: '更新订单明细',
        description: '支持部分字段更新（传哪些改哪些）。',
        tags: ['订单明细'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'order_id', type: 'number', description: '订单ID'),
                    new OA\Property(property: 'goods_id', type: 'number', nullable: true, description: '商品ID'),
                    new OA\Property(property: 'goods_name', type: 'string', description: '商品名称'),
                    new OA\Property(property: 'price', type: 'number', description: '单价(元)'),
                    new OA\Property(property: 'quantity', type: 'number', description: '数量'),
                    new OA\Property(property: 'amount', type: 'number', description: '小计(元)'),
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
        $this->authorize('mall_order_item.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rulesForUpdate())->validate();
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    #[OA\Delete(
        path: '/api/mall_order_item/{id}',
        operationId: 'mall_order_itemDestroy',
        summary: '删除订单明细',
        tags: ['订单明细'],
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
        $this->authorize('mall_order_item.delete');

        $this->service->delete($id);

        return ApiResponse::success(null, '已删除');
    }

    private function rules(): array
    {
        return [
            'order_id' => 'required|numeric',
            'goods_id' => 'nullable|numeric',
            'goods_name' => 'required|string|max:191',
            'price' => 'required|numeric',
            'quantity' => 'required|numeric',
            'amount' => 'required|numeric',
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
        'order_id' => $data['order_id'] ?? null,
        'goods_name' => $data['goods_name'] ?? null,
        ];
    }
}
