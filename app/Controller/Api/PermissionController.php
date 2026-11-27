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

use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Service\RbacService;
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
 * 权限树（菜单 + 按钮）的增删改查。
 *
 * GET 返回整棵树（带 children）；带 keyword 时返回平铺的过滤结果。
 * 角色授权弹窗也用这棵树渲染，不再单独提供 options 接口。
 */
class PermissionController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected RbacService $rbacService)
    {
    }

    /**
     * GET /api/permissions?keyword=.
     */
    #[SwGet(
        path: '/api/permissions',
        operationId: 'permissionIndex',
        summary: '权限树查询',
        tag: '权限管理',
        desc: '返回整棵权限树（menu/button 节点带 children）；带 keyword 时返回平铺的过滤结果。需要 permission.view 或 role.view 权限。',
        params: [new SwQuery('keyword', desc: '按名称/权限码过滤（返回平铺列表）')],
        responses: [new SwResp(desc: '权限树或平铺列表', props: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'parent_id', type: 'integer'),
                    new OA\Property(property: 'type', type: 'string', enum: ['menu', 'button']),
                    new OA\Property(property: 'code', type: 'string', example: 'mall_goods.edit'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'path', type: 'string', description: '菜单路由路径（仅 menu）'),
                    new OA\Property(property: 'children', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )),
        ])]
    )]
    public function index(): ResponseInterface
    {
        // 角色授权弹窗也要读这棵树，所以 role.view 也能看
        $this->authorize('permission.view', 'role.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'keyword' => 'nullable|string|max:64',
        ])->validate();

        $keyword = isset($data['keyword']) && trim((string) $data['keyword']) !== ''
            ? trim((string) $data['keyword'])
            : null;

        return ApiResponse::success($this->rbacService->permissionTree($keyword));
    }

    /**
     * POST /api/permissions.
     */
    #[SwPost(
        path: '/api/permissions',
        operationId: 'permissionStore',
        summary: '创建权限节点',
        tag: '权限管理',
        desc: '创建菜单或按钮权限；按钮只能挂在菜单下；创建后自动授予全部系统角色。需要 permission.add 权限。',
        body: new SwBody(
            props: [
                new SwProp('parent_id', type: 'integer', desc: '父节点 ID，0 为顶级', example: 41),
                new SwProp('type', desc: '节点类型', enum: ['menu', 'button']),
                new SwProp('code', desc: '权限码，小写字母开头的 a-z0-9_.', example: 'mall_goods.export'),
                new SwProp('name', example: '商品导出'),
                new SwProp('path', desc: '菜单路由路径（仅 menu 需要）', example: 'mall/goods'),
                new SwProp('icon', desc: '菜单图标名（仅 menu）', example: 'Goods'),
                new SwProp('visible', type: 'integer', desc: '侧边栏是否可见，默认 1', enum: [0, 1]),
                new SwProp('sort', type: 'integer', desc: '排序值，越小越靠前'),
                new SwProp('description'),
            ],
            requiredProps: ['type', 'code', 'name'],
        ),
        responses: [new SwResp(desc: '返回创建的权限节点')]
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('permission.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules(), $this->messages())->validate();

        return ApiResponse::success($this->rbacService->createPermission([
            'parent_id' => (int) ($data['parent_id'] ?? 0),
            'type' => (string) $data['type'],
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'path' => (string) ($data['path'] ?? ''),
            'icon' => (string) ($data['icon'] ?? ''),
            'visible' => (int) ($data['visible'] ?? 1),
            'sort' => (int) ($data['sort'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
        ]), '创建成功');
    }

    /**
     * PUT /api/permissions/{id}.
     */
    #[SwPut(
        path: '/api/permissions/{id}',
        operationId: 'permissionUpdate',
        summary: '更新权限节点',
        tag: '权限管理',
        desc: '按「传哪些改哪些」处理部分更新；type 创建后不可改；内置节点（is_system）不允许改权限码。需要 permission.edit 权限。',
        params: [new SwId()],
        body: new SwBody(
            props: [
                new SwProp('parent_id', type: 'integer'),
                new SwProp('code', desc: '权限码（内置节点不可改）'),
                new SwProp('name'),
                new SwProp('path'),
                new SwProp('icon'),
                new SwProp('visible', type: 'integer', enum: [0, 1]),
                new SwProp('sort', type: 'integer'),
                new SwProp('description'),
            ],
            required: false,
        ),
        responses: [new SwResp(desc: '返回更新后的权限节点')]
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('permission.edit');

        $rules = $this->rules();
        // 更新时排除自身，否则不改权限码也会被判重复
        $rules['code'] = 'nullable|string|max:64|regex:/^[a-z][a-z0-9_.]*$/|unique:permissions,code,' . $id;
        $rules['name'] = 'nullable|string|max:64';
        // 类型创建后不允许改，更新时不需要传
        $rules['type'] = 'nullable|string|in:menu,button';

        $data = $this->validatorFactory->make($this->request->all(), $rules, $this->messages())->validate();

        // 只提交了部分字段时按「改哪些传哪些」处理；空串不覆盖已有权限码与名称
        $payload = [];
        if (isset($data['parent_id'])) {
            $payload['parent_id'] = (int) $data['parent_id'];
        }
        foreach (['code', 'name'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $payload[$field] = (string) $data[$field];
            }
        }
        foreach (['path', 'icon', 'description'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $payload[$field] = (string) $data[$field];
            }
        }
        if (isset($data['visible'])) {
            $payload['visible'] = (int) $data['visible'];
        }
        if (isset($data['sort'])) {
            $payload['sort'] = (int) $data['sort'];
        }

        return ApiResponse::success($this->rbacService->updatePermission($id, $payload), '保存成功');
    }

    /**
     * DELETE /api/permissions/{id}.
     */
    #[SwDelete(
        path: '/api/permissions/{id}',
        operationId: 'permissionDestroy',
        summary: '删除权限节点',
        tag: '权限管理',
        desc: '有子节点或为内置节点（is_system）时拒绝删除。需要 permission.delete 权限。',
        params: [new SwId()],
        responses: [new SwResp(desc: '已删除')]
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('permission.delete');

        $this->rbacService->deletePermission($id);

        return ApiResponse::success(null, '已删除');
    }

    /**
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'parent_id' => 'nullable|integer|min:0',
            'type' => 'required|string|in:menu,button',
            'code' => 'required|string|max:64|regex:/^[a-z][a-z0-9_.]*$/|unique:permissions,code',
            'name' => 'required|string|max:64',
            'path' => 'nullable|string|max:128',
            'icon' => 'nullable|string|max:64',
            'visible' => 'nullable|integer|in:0,1',
            'sort' => 'nullable|integer|min:0|max:99999',
            'description' => 'nullable|string|max:255',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'type.required' => '请选择权限类型',
            'type.in' => '权限类型只能是 menu 或 button',
            'code.required' => '请填写权限码',
            'code.regex' => '权限码只能包含小写字母、数字、下划线和点，且以字母开头',
            'code.unique' => '该权限码已存在',
            'code.max' => '权限码最长 64 个字符',
            'name.required' => '请填写名称',
            'name.max' => '名称最长 64 个字符',
            'path.max' => '路由路径最长 128 个字符',
            'sort.integer' => '排序必须是整数',
        ];
    }
}
