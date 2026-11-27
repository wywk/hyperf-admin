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
use App\Model\MallOrder;
use App\Service\MallOrderService;
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwId;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwPut;
use App\Swagger\Attributes\SwQuery;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
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

    #[SwGet(
        path: '/api/mall_order',
        operationId: 'mall_orderIndex',
        summary: '订单管理列表',
        tag: '订单管理',
        page: true,
        params: [
            new SwQuery('order_no', type: 'string', desc: '订单号'),
            new SwQuery('receiver', type: 'string', desc: '收货人'),
            new SwQuery('phone', type: 'string', desc: '联系电话'),
            new SwQuery('status', type: 'string', desc: '订单状态'),
        ],
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

    #[SwGet(
        path: '/api/mall_order/{id}',
        operationId: 'mall_orderShow',
        summary: '订单管理详情',
        tag: '订单管理',
        params: [new SwId()],
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

    #[SwPost(
        path: '/api/mall_order',
        operationId: 'mall_orderStore',
        summary: '新增订单管理',
        tag: '订单管理',
        body: new SwBody(
            props: [
                new SwProp('order_no', type: 'string', desc: '订单号'),
                new SwProp('receiver', type: 'string', nullable: true, desc: '收货人'),
                new SwProp('phone', type: 'string', nullable: true, desc: '联系电话'),
                new SwProp('amount', type: 'number', desc: '金额(元)'),
                new SwProp('status', type: 'string', nullable: true, desc: '订单状态'),
                new SwProp('user_id', type: 'number', nullable: true, desc: '用户ID'),
                new SwProp('address', type: 'string', nullable: true, desc: '收货地址'),
                new SwProp('remark', type: 'string', nullable: true, desc: '备注'),
            ],
            requiredProps: ['order_no', 'amount'],
        ),
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('mall_order.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    #[SwPut(
        path: '/api/mall_order/{id}',
        operationId: 'mall_orderUpdate',
        summary: '更新订单管理',
        tag: '订单管理',
        desc: '支持部分字段更新（传哪些改哪些）。',
        params: [new SwId()],
        body: new SwBody(
            props: [
                new SwProp('order_no', type: 'string', desc: '订单号'),
                new SwProp('receiver', type: 'string', nullable: true, desc: '收货人'),
                new SwProp('phone', type: 'string', nullable: true, desc: '联系电话'),
                new SwProp('amount', type: 'number', desc: '金额(元)'),
                new SwProp('status', type: 'string', nullable: true, desc: '订单状态'),
                new SwProp('user_id', type: 'number', nullable: true, desc: '用户ID'),
                new SwProp('address', type: 'string', nullable: true, desc: '收货地址'),
                new SwProp('remark', type: 'string', nullable: true, desc: '备注'),
            ],
            required: false,
        ),
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('mall_order.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rulesForUpdate())->validate();
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    #[SwDelete(
        path: '/api/mall_order/{id}',
        operationId: 'mall_orderDestroy',
        summary: '删除订单管理',
        tag: '订单管理',
        params: [new SwId()],
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
