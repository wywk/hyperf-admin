<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Controller\Api;

use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Service\DepartmentService;
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwPut;
use App\Swagger\Attributes\SwResp;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 部门管理：树形增删改查。数据权限的「本部门 / 本部门及下级」依赖这棵树。
 */
class DepartmentController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected DepartmentService $departmentService)
    {
    }

    /**
     * GET /api/departments.
     */
    #[SwGet(path: '/api/departments', operationId: 'departmentTree', summary: '部门树', tag: '部门管理',
        desc: '返回完整部门树（含每个部门的用户数）。需要 dept.view 权限。',
        responses: [new SwResp(desc: '部门树', props: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
                new OA\Property(property: 'id', type: 'integer'),
                new OA\Property(property: 'parent_id', type: 'integer'),
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'user_count', type: 'integer'),
                new OA\Property(property: 'children', type: 'array', items: new OA\Items(type: 'object')),
            ])),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('dept.view');

        return ApiResponse::success($this->departmentService->tree());
    }

    /**
     * POST /api/departments.
     */
    #[SwPost(path: '/api/departments', operationId: 'departmentStore', summary: '创建部门', tag: '部门管理',
        desc: 'parent_id 为 0 表示顶级部门。需要 dept.add 权限。',
        body: new SwBody(
            props: [
                new SwProp('parent_id', type: 'integer', desc: '上级部门 ID，0=顶级'),
                new SwProp('name', desc: '部门名称'),
                new SwProp('code', desc: '唯一标识，字母开头'),
                new SwProp('leader', desc: '负责人'),
                new SwProp('phone', desc: '联系电话'),
                new SwProp('sort', type: 'integer'),
                new SwProp('status', type: 'integer', enum: [0, 1]),
            ],
            requiredProps: ['name', 'code'],
        ),
        responses: [new SwResp(desc: '创建成功的部门')]
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('dept.add');

        $data = $this->validatorFactory->make($this->request->all(), [
            'parent_id' => 'nullable|integer|min:0',
            'name' => 'required|string|max:64',
            'code' => 'required|string|max:32|regex:/^[a-z][a-z0-9_-]*$/',
            'leader' => 'nullable|string|max:32',
            'phone' => 'nullable|string|max:20',
            'sort' => 'nullable|integer|min:0|max:99999',
            'status' => 'nullable|integer|in:0,1',
        ], [
            'name.required' => '请填写部门名称',
            'code.required' => '请填写部门标识',
            'code.regex' => '部门标识只能包含小写字母、数字、下划线和短横线，且以字母开头',
            'parent_id.min' => '上级部门 ID 不合法',
        ])->validate();

        return ApiResponse::success($this->departmentService->create([
            'parent_id' => (int) ($data['parent_id'] ?? 0),
            'name' => (string) $data['name'],
            'code' => (string) $data['code'],
            'leader' => (string) ($data['leader'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
        ]), '创建成功');
    }

    /**
     * PUT /api/departments/{id}.
     */
    #[SwPut(path: '/api/departments/{id}', operationId: 'departmentUpdate', summary: '更新部门', tag: '部门管理',
        desc: '可调整上级部门（不能选自己或自己的下级），移动后子树路径自动重算。需要 dept.edit 权限。',
        body: new SwBody(
            props: [
                new SwProp('parent_id', type: 'integer'),
                new SwProp('name'),
                new SwProp('code'),
                new SwProp('leader'),
                new SwProp('phone'),
                new SwProp('sort', type: 'integer'),
                new SwProp('status', type: 'integer', enum: [0, 1]),
            ],
            required: false,
        ),
        responses: [new SwResp(desc: '更新后的部门')]
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('dept.edit');

        $data = $this->validatorFactory->make($this->request->all(), [
            'parent_id' => 'nullable|integer|min:0',
            'name' => 'nullable|string|max:64',
            'code' => 'nullable|string|max:32|regex:/^[a-z][a-z0-9_-]*$/',
            'leader' => 'nullable|string|max:32',
            'phone' => 'nullable|string|max:20',
            'sort' => 'nullable|integer|min:0|max:99999',
            'status' => 'nullable|integer|in:0,1',
        ])->validate();

        return ApiResponse::success($this->departmentService->update($id, $data), '保存成功');
    }

    /**
     * DELETE /api/departments/{id}.
     */
    #[SwDelete(path: '/api/departments/{id}', operationId: 'departmentDestroy', summary: '删除部门', tag: '部门管理',
        desc: '有子部门或仍有关联用户时拒绝删除。需要 dept.delete 权限。',
        responses: [new SwResp(desc: '已删除')]
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('dept.delete');

        $this->departmentService->delete($id);

        return ApiResponse::success(null, '已删除');
    }
}
