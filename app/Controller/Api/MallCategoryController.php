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
use App\Model\MallCategory;
use App\Service\MallCategoryService;
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
 * MallCategory CRUD 接口（由代码生成器生成）。
 *
 * 路由：/api/mall_category（config/routes.php 里的 [gen:mall_category] 标记块）。
 * 权限码：mall_category.view/.add/.edit/.delete（生成器已注册到权限树）。
 */
class MallCategoryController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected MallCategoryService $service)
    {
    }

    #[SwGet(
        path: '/api/mall_category',
        operationId: 'mall_categoryIndex',
        summary: '分类管理列表',
        tag: '分类管理',
        page: true,
        params: [
            new SwQuery('name', type: 'string', desc: '分类名称'),
            new SwQuery('status', type: 'string', desc: '状态'),
        ],
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('mall_category.view');

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
        path: '/api/mall_category/{id}',
        operationId: 'mall_categoryShow',
        summary: '分类管理详情',
        tag: '分类管理',
        params: [new SwId()],
    )]
    public function show(int $id): ResponseInterface
    {
        $this->authorize('mall_category.view');

        $model = MallCategory::query()->find($id);
        if ($model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success($model);
    }

    #[SwPost(
        path: '/api/mall_category',
        operationId: 'mall_categoryStore',
        summary: '新增分类管理',
        tag: '分类管理',
        body: new SwBody(
            props: [
                new SwProp('name', type: 'string', desc: '分类名称'),
                new SwProp('sort', type: 'number', nullable: true, desc: '排序'),
                new SwProp('status', type: 'string', nullable: true, desc: '状态'),
                new SwProp('description', type: 'string', nullable: true, desc: '说明'),
            ],
            requiredProps: ['name'],
        ),
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('mall_category.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    #[SwPut(
        path: '/api/mall_category/{id}',
        operationId: 'mall_categoryUpdate',
        summary: '更新分类管理',
        tag: '分类管理',
        desc: '支持部分字段更新（传哪些改哪些）。',
        params: [new SwId()],
        body: new SwBody(
            props: [
                new SwProp('name', type: 'string', desc: '分类名称'),
                new SwProp('sort', type: 'number', nullable: true, desc: '排序'),
                new SwProp('status', type: 'string', nullable: true, desc: '状态'),
                new SwProp('description', type: 'string', nullable: true, desc: '说明'),
            ],
            required: false,
        ),
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('mall_category.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rulesForUpdate())->validate();
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    #[SwDelete(
        path: '/api/mall_category/{id}',
        operationId: 'mall_categoryDestroy',
        summary: '删除分类管理',
        tag: '分类管理',
        params: [new SwId()],
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('mall_category.delete');

        $this->service->delete($id);

        return ApiResponse::success(null, '已删除');
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'sort' => 'nullable|numeric',
            'status' => 'nullable|in:1,0',
            'description' => 'nullable|string|max:2000',
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
            'name' => $data['name'] ?? null,
            'status' => $data['status'] ?? null,
        ];
    }
}
