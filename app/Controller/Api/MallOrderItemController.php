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
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwId;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwPut;
use App\Swagger\Attributes\SwQuery;
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

    #[SwGet(path: '/api/mall_order_item', operationId: 'mall_order_itemIndex', summary: '订单明细列表', tag: '订单明细', page: true,
    params: [
        new SwQuery('order_id', type: 'number', desc: '订单ID'),
        new SwQuery('goods_name', type: 'string', desc: '商品名称'),
    ],
)]    public function index(): ResponseInterface
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

    #[SwGet(path: '/api/mall_order_item/{id}', operationId: 'mall_order_itemShow', summary: '订单明细详情', tag: '订单明细',
    params: [new SwId()],
)]    public function show(int $id): ResponseInterface
    {
        $this->authorize('mall_order_item.view');

        $model = MallOrderItem::query()->find($id);
        if ($model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success($model);
    }

    #[SwPost(path: '/api/mall_order_item', operationId: 'mall_order_itemStore', summary: '新增订单明细', tag: '订单明细',
    body: new SwBody(
        props: [
            new SwProp('order_id', type: 'number', desc: '订单ID'),
            new SwProp('goods_id', type: 'number', nullable: true, desc: '商品ID'),
            new SwProp('goods_name', type: 'string', desc: '商品名称'),
            new SwProp('price', type: 'number', desc: '单价(元)'),
            new SwProp('quantity', type: 'number', desc: '数量'),
            new SwProp('amount', type: 'number', desc: '小计(元)'),
        ],
        requiredProps: ['order_id', 'goods_name', 'price', 'quantity', 'amount'],
    ),
)]    public function store(): ResponseInterface
    {
        $this->authorize('mall_order_item.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    #[SwPut(path: '/api/mall_order_item/{id}', operationId: 'mall_order_itemUpdate', summary: '更新订单明细', tag: '订单明细',
    desc: '支持部分字段更新（传哪些改哪些）。',
    params: [new SwId()],
    body: new SwBody(
        props: [
            new SwProp('order_id', type: 'number', desc: '订单ID'),
            new SwProp('goods_id', type: 'number', nullable: true, desc: '商品ID'),
            new SwProp('goods_name', type: 'string', desc: '商品名称'),
            new SwProp('price', type: 'number', desc: '单价(元)'),
            new SwProp('quantity', type: 'number', desc: '数量'),
            new SwProp('amount', type: 'number', desc: '小计(元)'),
        ],
        required: false,
    ),
)]    public function update(int $id): ResponseInterface
    {
        $this->authorize('mall_order_item.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rulesForUpdate())->validate();
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    #[SwDelete(path: '/api/mall_order_item/{id}', operationId: 'mall_order_itemDestroy', summary: '删除订单明细', tag: '订单明细',
    params: [new SwId()],
)]    public function destroy(int $id): ResponseInterface
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
