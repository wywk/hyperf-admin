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
use App\Model\MallGoodsSku;
use App\Service\MallGoodsSkuService;
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
 * MallGoodsSku CRUD 接口（由代码生成器生成）。
 *
 * 路由：/api/mall_goods_sku（config/routes.php 里的 [gen:mall_goods_sku] 标记块）。
 * 权限码：mall_goods_sku.view/.add/.edit/.delete（生成器已注册到权限树）。
 */
class MallGoodsSkuController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected MallGoodsSkuService $service)
    {
    }

    #[SwGet(
        path: '/api/mall_goods_sku',
        operationId: 'mall_goods_skuIndex',
        summary: 'SKU管理列表',
        tag: 'SKU管理',
        page: true,
        params: [
            new SwQuery('goods_id', type: 'number', desc: '商品ID'),
            new SwQuery('sku_code', type: 'string', desc: 'SKU编码'),
            new SwQuery('status', type: 'string', desc: '状态'),
        ],
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('mall_goods_sku.view');

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
        path: '/api/mall_goods_sku/{id}',
        operationId: 'mall_goods_skuShow',
        summary: 'SKU管理详情',
        tag: 'SKU管理',
        params: [new SwId()],
    )]
    public function show(int $id): ResponseInterface
    {
        $this->authorize('mall_goods_sku.view');

        $model = MallGoodsSku::query()->find($id);
        if ($model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success($model);
    }

    #[SwPost(
        path: '/api/mall_goods_sku',
        operationId: 'mall_goods_skuStore',
        summary: '新增SKU管理',
        tag: 'SKU管理',
        body: new SwBody(
            props: [
                new SwProp('goods_id', type: 'number', desc: '商品ID'),
                new SwProp('sku_code', type: 'string', desc: 'SKU编码'),
                new SwProp('specs', type: 'string', nullable: true, desc: '规格'),
                new SwProp('price', type: 'number', desc: '售价(元)'),
                new SwProp('stock', type: 'number', nullable: true, desc: '库存'),
                new SwProp('status', type: 'string', nullable: true, desc: '状态'),
            ],
            requiredProps: ['goods_id', 'sku_code', 'price'],
        ),
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('mall_goods_sku.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    #[SwPut(
        path: '/api/mall_goods_sku/{id}',
        operationId: 'mall_goods_skuUpdate',
        summary: '更新SKU管理',
        tag: 'SKU管理',
        desc: '支持部分字段更新（传哪些改哪些）。',
        params: [new SwId()],
        body: new SwBody(
            props: [
                new SwProp('goods_id', type: 'number', desc: '商品ID'),
                new SwProp('sku_code', type: 'string', desc: 'SKU编码'),
                new SwProp('specs', type: 'string', nullable: true, desc: '规格'),
                new SwProp('price', type: 'number', desc: '售价(元)'),
                new SwProp('stock', type: 'number', nullable: true, desc: '库存'),
                new SwProp('status', type: 'string', nullable: true, desc: '状态'),
            ],
            required: false,
        ),
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('mall_goods_sku.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rulesForUpdate())->validate();
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    #[SwDelete(
        path: '/api/mall_goods_sku/{id}',
        operationId: 'mall_goods_skuDestroy',
        summary: '删除SKU管理',
        tag: 'SKU管理',
        params: [new SwId()],
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('mall_goods_sku.delete');

        $this->service->delete($id);

        return ApiResponse::success(null, '已删除');
    }

    private function rules(): array
    {
        return [
            'goods_id' => 'required|numeric',
            'sku_code' => 'required|string|max:191',
            'specs' => 'nullable|string|max:191',
            'price' => 'required|numeric',
            'stock' => 'nullable|numeric',
            'status' => 'nullable|in:1,0',
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
            'goods_id' => $data['goods_id'] ?? null,
            'sku_code' => $data['sku_code'] ?? null,
            'status' => $data['status'] ?? null,
        ];
    }
}
