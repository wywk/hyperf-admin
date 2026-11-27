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
use App\Exception\BusinessException;
use App\Model\User;
use App\Service\UserService;
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwId;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwPut;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 用户管理：账号增删改查、启停用、重置密码。
 */
class UserController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected UserService $userService)
    {
    }

    /**
     * GET /api/users.
     */
    #[SwGet(
        path: '/api/users',
        operationId: 'userIndex',
        summary: '用户分页列表',
        tag: '用户管理',
        page: true,
        desc: '支持按用户名/昵称/邮箱模糊搜索，按角色与状态过滤。需要 user.view 权限。',
        params: [
            new SwQuery('keyword', desc: '用户名 / 昵称 / 邮箱模糊匹配'),
            new SwQuery('role', desc: '角色标识（精确匹配）'),
            new SwQuery('status', type: 'integer', desc: '状态 1=启用 0=禁用'),
        ],
        responses: [new SwResp(desc: '分页结果：{total, page, page_size, items[]}', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
            ]),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('user.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
            'keyword' => 'nullable|string|max:64',
            'role' => 'nullable|string|max:32',
            'status' => 'nullable|integer|in:0,1',
            'dept_id' => 'nullable|integer|min:0',
        ], [
            'page_size.between' => 'page_size 取值范围 1-100',
            'status.in' => 'status 只能是 0 或 1',
        ])->validate();

        $operator = Context::get('user');

        return ApiResponse::success($this->userService->paginate(
            [
                'keyword' => $data['keyword'] ?? null,
                'role' => $data['role'] ?? null,
                'status' => $data['status'] ?? null,
                'dept_id' => $data['dept_id'] ?? null,
            ],
            max(1, (int) ($data['page'] ?? 1)),
            min(100, max(1, (int) ($data['page_size'] ?? 20))),
            $operator instanceof User ? $operator : null
        ));
    }

    /**
     * POST /api/users.
     */
    #[SwPost(
        path: '/api/users',
        operationId: 'userStore',
        summary: '创建用户',
        tag: '用户管理',
        desc: '密码使用 bcrypt 哈希存储。需要 user.add 权限。',
        body: new SwBody(
            props: [
                new SwProp('username', desc: '登录名，字母开头，3-64 位', example: 'auditor'),
                new SwProp('password', desc: '密码，6-64 位', example: 'audit123456'),
                new SwProp('nickname', desc: '昵称', example: '审计员'),
                new SwProp('email', example: 'auditor@example.com'),
                new SwProp('role', desc: '角色标识', example: 'user'),
                new SwProp('status', type: 'integer', enum: [0, 1], desc: '默认 1=启用'),
            ],
            requiredProps: ['username', 'password', 'role'],
        ),
        responses: [new SwResp(desc: '返回创建的用户（不含密码）')]
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('user.add');

        $data = $this->validatorFactory->make($this->request->all(), [
            'username' => 'required|string|between:3,64|regex:/^[a-zA-Z][a-zA-Z0-9_.-]*$/|unique:users,username',
            'password' => 'required|string|between:6,64',
            'nickname' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:128',
            'role' => 'required|string|max:32',
            'dept_id' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
        ], $this->messages())->validate();

        return ApiResponse::success($this->userService->create([
            'username' => (string) $data['username'],
            'password' => (string) $data['password'],
            'nickname' => (string) ($data['nickname'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'role' => (string) $data['role'],
            'dept_id' => (int) ($data['dept_id'] ?? 0),
            'status' => (int) ($data['status'] ?? 1),
        ]), '创建成功');
    }

    /**
     * PUT /api/users/{id}.
     */
    #[SwPut(
        path: '/api/users/{id}',
        operationId: 'userUpdate',
        summary: '更新用户',
        tag: '用户管理',
        desc: '部分更新。改角色或停用会立即吊销该用户的全部登录会话。需要 user.edit 权限。',
        params: [new SwId()],
        body: new SwBody(
            props: [
                new SwProp('username'),
                new SwProp('nickname'),
                new SwProp('email'),
                new SwProp('role', desc: '角色标识'),
                new SwProp('status', type: 'integer', enum: [0, 1]),
            ],
            required: false,
        ),
        responses: [new SwResp(desc: '返回更新后的用户')]
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('user.edit');

        $rules = [
            'username' => 'nullable|string|between:3,64|regex:/^[a-zA-Z][a-zA-Z0-9_.-]*$/',
            'nickname' => 'nullable|string|max:64',
            'email' => 'nullable|email|max:128',
            'role' => 'nullable|string|max:32',
            'dept_id' => 'nullable|integer|min:0',
            'status' => 'nullable|integer|in:0,1',
        ];

        $data = $this->validatorFactory->make($this->request->all(), $rules, $this->messages())->validate();

        $payload = [];
        foreach (['username', 'role'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $payload[$field] = (string) $data[$field];
            }
        }
        foreach (['nickname', 'email'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $payload[$field] = (string) $data[$field];
            }
        }
        if (array_key_exists('dept_id', $data)) {
            // 0 或 null 表示清空部门
            $payload['dept_id'] = (int) ($data['dept_id'] ?? 0);
        }
        if (isset($data['status'])) {
            $payload['status'] = (int) $data['status'];
        }

        return ApiResponse::success(
            $this->userService->update($id, $payload, $this->operatorId()),
            '保存成功'
        );
    }

    /**
     * PUT /api/users/{id}/status：启用 / 停用.
     */
    #[SwPut(
        path: '/api/users/{id}/status',
        operationId: 'userUpdateStatus',
        summary: '启用或停用用户',
        tag: '用户管理',
        desc: '停用后该用户立即无法登录，已登录的会话全部失效；不能停用自己或最后一个可用管理员。需要 user.edit 权限。',
        params: [new SwId()],
        body: new SwBody(props: [new SwProp('status', type: 'integer', enum: [0, 1])], requiredProps: ['status']),
        responses: [new SwResp(desc: '返回更新后的用户')]
    )]
    public function updateStatus(int $id): ResponseInterface
    {
        $this->authorize('user.edit');

        $data = $this->validatorFactory->make($this->request->all(), [
            'status' => 'required|integer|in:0,1',
        ], [
            'status.required' => '请提供 status',
            'status.in' => 'status 只能是 0 或 1',
        ])->validate();

        return ApiResponse::success(
            $this->userService->updateStatus($id, (int) $data['status'], $this->operatorId()),
            (int) $data['status'] === 1 ? '已启用' : '已停用'
        );
    }

    /**
     * PUT /api/users/{id}/password：管理员重置密码.
     */
    #[SwPut(
        path: '/api/users/{id}/password',
        operationId: 'userResetPassword',
        summary: '重置用户密码',
        tag: '用户管理',
        desc: '管理员直接设置新密码，无需旧密码；重置后该用户全部会话下线，需用新密码重新登录。需要 user.reset 权限。',
        params: [new SwId()],
        body: new SwBody(
            props: [new SwProp('password', desc: '新密码，6-64 位', example: 'newpass123456')],
            requiredProps: ['password'],
        ),
        responses: [new SwResp(desc: '已重置')]
    )]
    public function resetPassword(int $id): ResponseInterface
    {
        $this->authorize('user.reset');

        $data = $this->validatorFactory->make($this->request->all(), [
            'password' => 'required|string|between:6,64',
        ], [
            'password.required' => '请填写新密码',
            'password.between' => '新密码长度需为 6-64 个字符',
        ])->validate();

        $this->userService->resetPassword($id, (string) $data['password'], $this->operatorId());

        return ApiResponse::success(null, '密码已重置，该用户的登录会话已全部失效');
    }

    /**
     * DELETE /api/users/{id}.
     */
    #[SwDelete(
        path: '/api/users/{id}',
        operationId: 'userDestroy',
        summary: '删除用户',
        tag: '用户管理',
        desc: '不能删除自己或最后一个可用管理员；删除后会话立即失效。需要 user.delete 权限。',
        params: [new SwId()],
        responses: [new SwResp(desc: '已删除')]
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('user.delete');

        $this->userService->delete($id, $this->operatorId());

        return ApiResponse::success(null, '已删除');
    }

    private function operatorId(): int
    {
        $user = Context::get('user');
        if (! $user instanceof User) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '未登录，请先登录');
        }

        return (int) $user->id;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'username.required' => '请填写用户名',
            'username.between' => '用户名长度需为 3-64 个字符',
            'username.regex' => '用户名只能包含字母、数字、下划线、点和短横线，且以字母开头',
            'username.unique' => '该用户名已被占用',
            'password.required' => '请填写密码',
            'password.between' => '密码长度需为 6-64 个字符',
            'email.email' => '邮箱格式不正确',
            'role.required' => '请选择角色',
            'status.in' => '状态只能是 0 或 1',
        ];
    }
}
