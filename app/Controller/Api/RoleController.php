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
use App\Swagger\Attributes\SwResp;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 角色管理：增删改查 + 给角色分配权限。
 */
class RoleController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected RbacService $rbacService)
    {
    }

    /**
     * GET /api/roles.
     */
    #[SwGet(path: '/api/roles', operationId: 'roleIndex', summary: '角色列表', tag: '角色管理',
        desc: '返回全部角色（平铺，不分页），含 is_system 标记与权限数量。',
        responses: [new SwResp(desc: '角色列表', props: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'code', type: 'string', example: 'admin'),
                    new OA\Property(property: 'name', type: 'string', example: '超级管理员'),
                    new OA\Property(property: 'is_system', type: 'integer', description: '系统内置角色不可删除'),
                    new OA\Property(property: 'permission_count', type: 'integer'),
                ]
            )),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('role.view');

        return ApiResponse::success($this->rbacService->listRoles());
    }

    /**
     * POST /api/roles.
     */
    #[SwPost(path: '/api/roles', operationId: 'roleStore', summary: '创建角色', tag: '角色管理',
        desc: '需要 role.add 权限。',
        body: new SwBody(
            props: [
                new SwProp('code', desc: '角色标识，小写开头的 a-z0-9_-', example: 'operator'),
                new SwProp('name', example: '运营人员'),
                new SwProp('description'),
                new SwProp('sort', type: 'integer'),
            ],
            requiredProps: ['code', 'name'],
        ),
        responses: [new SwResp(desc: '返回创建的角色')]
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('role.add');

        $data = $this->validatorFactory->make($this->request->all(), [
            'code' => 'required|string|max:32|regex:/^[a-z][a-z0-9_-]*$/|unique:roles,code',
            'name' => 'required|string|max:64',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0|max:99999',
        ], $this->messages())->validate();

        return ApiResponse::success($this->rbacService->createRole([
            'code' => (string) $data['code'],
            'name' => (string) $data['name'],
            'description' => (string) ($data['description'] ?? ''),
            'sort' => (int) ($data['sort'] ?? 0),
        ]), '创建成功');
    }

    /**
     * PUT /api/roles/{id}.
     */
    #[SwPut(path: '/api/roles/{id}', operationId: 'roleUpdate', summary: '更新角色', tag: '角色管理',
        desc: '部分更新：传哪些改哪些；内置角色不允许改标识。需要 role.edit 权限。',
        params: [new SwId()],
        body: new SwBody(
            props: [
                new SwProp('code'),
                new SwProp('name'),
                new SwProp('description'),
                new SwProp('sort', type: 'integer'),
            ],
            required: false,
        ),
        responses: [new SwResp(desc: '返回更新后的角色')]
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('role.edit');

        $data = $this->validatorFactory->make($this->request->all(), [
            'code' => 'nullable|string|max:32|regex:/^[a-z][a-z0-9_-]*$/|unique:roles,code,' . $id,
            'name' => 'nullable|string|max:64',
            'description' => 'nullable|string|max:255',
            'sort' => 'nullable|integer|min:0|max:99999',
        ], $this->messages())->validate();

        $payload = [];
        if (isset($data['code']) && $data['code'] !== '') {
            $payload['code'] = (string) $data['code'];
        }
        if (isset($data['name']) && $data['name'] !== '') {
            $payload['name'] = (string) $data['name'];
        }
        if (array_key_exists('description', $data) && $data['description'] !== null) {
            $payload['description'] = (string) $data['description'];
        }
        if (isset($data['sort'])) {
            $payload['sort'] = (int) $data['sort'];
        }

        return ApiResponse::success($this->rbacService->updateRole($id, $payload), '保存成功');
    }

    /**
     * DELETE /api/roles/{id}.
     */
    #[SwDelete(path: '/api/roles/{id}', operationId: 'roleDestroy', summary: '删除角色', tag: '角色管理',
        desc: '内置角色（is_system）不可删除；有用户绑定时拒绝删除。需要 role.delete 权限。',
        params: [new SwId()],
        responses: [new SwResp(desc: '已删除')]
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('role.delete');

        $this->rbacService->deleteRole($id);

        return ApiResponse::success(null, '已删除');
    }

    /**
     * GET /api/roles/{id}/permissions：该角色已勾选的权限 ID。
     */
    #[SwGet(path: '/api/roles/{id}/permissions', operationId: 'rolePermissionIds', summary: '角色已授权的权限 ID', tag: '角色管理',
        desc: '用于角色授权弹窗回显（前端只需勾选叶子节点）。需要 role.view 权限。',
        params: [new SwId()],
        responses: [new SwResp(desc: '权限 ID 集合', props: [
            new OA\Property(property: 'data', properties: [
                new OA\Property(property: 'permission_ids', type: 'array', items: new OA\Items(type: 'integer'), example: [41, 42, 47, 68]),
            ]),
        ])]
    )]
    public function permissions(int $id): ResponseInterface
    {
        $this->authorize('role.view');

        return ApiResponse::success(['permission_ids' => $this->rbacService->rolePermissionIds($id)]);
    }

    /**
     * PUT /api/roles/{id}/permissions：覆盖式保存角色权限。
     */
    #[SwPut(path: '/api/roles/{id}/permissions', operationId: 'roleSyncPermissions', summary: '覆盖式保存角色授权', tag: '角色管理',
        desc: '全量覆盖该角色的权限；系统角色会强制补回 is_system 权限防止锁死。保存后 Redis 权限缓存立即失效。需要 role.assign 权限。',
        params: [new SwId()],
        body: new SwBody(
            props: [
                new SwProp('permission_ids', type: 'array', desc: '全量权限 ID 列表（可为空数组）',
                    items: new OA\Items(type: 'integer'), example: [1, 2, 41, 47]),
            ],
            requiredProps: ['permission_ids'],
        ),
        responses: [new SwResp(desc: '返回实际生效的权限 ID 集合')]
    )]
    public function syncPermissions(int $id): ResponseInterface
    {
        $this->authorize('role.assign');

        $data = $this->validatorFactory->make($this->request->all(), [
            'permission_ids' => 'present|array',
            'permission_ids.*' => 'integer|min:1',
        ], [
            'permission_ids.present' => '请提交 permission_ids（可以为空数组）',
            'permission_ids.array' => 'permission_ids 必须是数组',
            'permission_ids.*.integer' => 'permission_ids 只能包含数字',
        ])->validate();

        $ids = array_map('intval', (array) $data['permission_ids']);

        return ApiResponse::success(
            ['permission_ids' => $this->rbacService->syncRolePermissions($id, $ids)],
            '授权已保存'
        );
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'code.required' => '请填写角色标识',
            'code.regex' => '角色标识只能包含小写字母、数字、下划线和短横线，且以字母开头',
            'code.unique' => '该角色标识已存在',
            'name.required' => '请填写角色名称',
            'name.max' => '角色名称最长 64 个字符',
            'sort.integer' => '排序必须是整数',
        ];
    }
}
