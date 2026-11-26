<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\Department;
use App\Model\Notification;
use App\Model\Role;
use App\Model\User;
use Hyperf\DbConnection\Db;

/**
 * 用户管理：增删改查、启停用、重置密码。
 *
 * 三条保护规则（都是「别把后台锁死」这类事故的高发点）：
 * - 不能停用/删除自己（避免手一滑把自己踢出后台）
 * - 不能让系统里最后一个可用的管理员消失（降级/停用/删除都要检查）
 * - 停用、删除、重置密码、改角色都会吊销该用户的全部登录会话
 */
class UserService
{
    public function __construct(
        protected TokenService $tokenService,
        protected NotificationService $notificationService,
        protected PasswordPolicyService $passwordPolicy,
        protected DataScopeService $dataScope
    ) {
    }

    /**
     * @param array{keyword?: null|string, role?: null|string, status?: null|int, dept_id?: null|int} $filters
     * @param null|User $operator 传了就按该用户的数据范围过滤，null = 不过滤（内部/测试用）
     * @return array{total: int, page: int, page_size: int, items: array<int, array<string, mixed>>}
     */
    public function paginate(array $filters, int $page, int $pageSize, ?User $operator = null): array
    {
        $query = User::query();

        if ($operator !== null) {
            $this->dataScope->applyToUsers($query, $operator);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $needle = '%' . $keyword . '%';
            $query->where(static function ($q) use ($needle) {
                $q->where('username', 'like', $needle)
                    ->orWhere('nickname', 'like', $needle)
                    ->orWhere('email', 'like', $needle);
            });
        }

        $role = trim((string) ($filters['role'] ?? ''));
        if ($role !== '') {
            $query->where('role', $role);
        }

        if (isset($filters['status']) && $filters['status'] !== null && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        $deptId = (int) ($filters['dept_id'] ?? 0);
        if ($deptId > 0) {
            $query->where('dept_id', $deptId);
        }

        $total = (int) $query->count();

        /** @var array<int, User> $rows */
        $rows = $query->orderBy('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->all();

        $roleNames = Role::query()->pluck('name', 'code')->all();
        $deptNames = Department::query()->pluck('name', 'id')->all();

        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->format($row) + [
                'role_name' => (string) ($roleNames[(string) $row->role] ?? ''),
                'dept_name' => $row->dept_id !== null ? (string) ($deptNames[(int) $row->dept_id] ?? '') : '',
            ];
        }

        return ['total' => $total, 'page' => $page, 'page_size' => $pageSize, 'items' => $items];
    }

    /**
     * @param array{username: string, password: string, nickname?: string, email?: string, role: string, dept_id?: null|int, status?: int} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $this->assertRoleExists($data['role']);

        if (User::query()->where('username', $data['username'])->exists()) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '该用户名已被占用');
        }

        $this->passwordPolicy->validate($data['password'], $data['username']);

        $user = User::query()->create([
            'username' => $data['username'],
            'password' => password_hash($data['password'], PASSWORD_BCRYPT),
            'nickname' => (string) ($data['nickname'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'role' => $data['role'],
            'dept_id' => isset($data['dept_id']) && (int) $data['dept_id'] > 0 ? (int) $data['dept_id'] : null,
            'status' => (int) ($data['status'] ?? 1),
        ]);
        $user->password_changed_at = date('Y-m-d H:i:s');
        $user->save();

        $this->notificationService->push(
            (int) $user->id,
            '欢迎加入 Hyperf Admin',
            '账号已创建，首次登录后建议到「个人中心」修改初始密码。',
            Notification::TYPE_SYSTEM,
            Notification::LEVEL_SUCCESS,
            '/profile'
        );

        return $this->format($user);
    }

    /**
     * 部分更新：传哪些改哪些。改角色/停用会吊销该用户的会话。
     *
     * @param array{username?: string, nickname?: string, email?: string, role?: string, dept_id?: null|int, status?: int} $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data, int $operatorId): array
    {
        $user = $this->findOrFail($id);
        $this->assertCanManage($operatorId, $user);

        if (isset($data['username']) && $data['username'] !== (string) $user->username) {
            if (User::query()->where('username', $data['username'])->where('id', '<>', $id)->exists()) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, '该用户名已被占用');
            }
            $user->username = $data['username'];
        }

        if (isset($data['role']) && $data['role'] !== (string) $user->role) {
            $this->assertRoleExists($data['role']);
            $this->assertNotLastAdmin($user, '不能把最后一个可用管理员改成其他角色');

            $user->role = $data['role'];
        }

        if (isset($data['status']) && (int) $data['status'] !== (int) $user->status) {
            $status = (int) $data['status'] === 1 ? 1 : 0;
            if ($status === 0) {
                $this->assertNotSelf($id, $operatorId, '不能停用自己的账号');
                $this->assertNotLastAdmin($user, '不能停用最后一个可用管理员');
            }
            $user->status = $status;
        }

        foreach (['nickname', 'email'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $user->{$field} = (string) $data[$field];
            }
        }

        // 部门可以清空（传 0 或 null），传了就校验存在
        if (array_key_exists('dept_id', $data)) {
            $deptId = (int) ($data['dept_id'] ?? 0);
            if ($deptId > 0 && ! Department::query()->where('id', $deptId)->exists()) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, '所选部门不存在');
            }
            $user->dept_id = $deptId > 0 ? $deptId : null;
        }

        $roleChanged = $user->isDirty('role');
        $disabled = $user->isDirty('status') && (int) $user->status === 0;
        $user->save();

        if ($roleChanged || $disabled) {
            // 权限或账号状态变了，旧 token 不能继续用
            $this->tokenService->revokeAllForUser($id);
        }

        if ($roleChanged) {
            $roleName = (string) Role::query()->where('code', (string) $user->role)->value('name');
            $this->notificationService->push(
                $id,
                '你的角色已变更',
                sprintf('角色已调整为「%s」，相关菜单与权限立即生效。', $roleName !== '' ? $roleName : (string) $user->role),
                Notification::TYPE_ACCOUNT,
                Notification::LEVEL_INFO,
                '/profile'
            );
        }

        if ($disabled) {
            $this->notificationService->push(
                $id,
                '账号已被停用',
                '你的账号已被管理员停用，当前所有登录会话已失效。如有疑问请联系系统管理员。',
                Notification::TYPE_ACCOUNT,
                Notification::LEVEL_WARNING
            );
        }

        return $this->format($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(int $id, int $status, int $operatorId): array
    {
        $user = $this->findOrFail($id);
        $this->assertCanManage($operatorId, $user);
        $status = $status === 1 ? 1 : 0;

        if ($status === (int) $user->status) {
            return $this->format($user);
        }

        if ($status === 0) {
            $this->assertNotSelf($id, $operatorId, '不能停用自己的账号');
            $this->assertNotLastAdmin($user, '不能停用最后一个可用管理员');
        }

        $user->status = $status;
        $user->save();

        if ($status === 0) {
            $this->tokenService->revokeAllForUser($id);
            $this->notificationService->push(
                $id,
                '账号已被停用',
                '你的账号已被管理员停用，当前所有登录会话已失效。如有疑问请联系系统管理员。',
                Notification::TYPE_ACCOUNT,
                Notification::LEVEL_WARNING
            );
        }

        return $this->format($user);
    }

    public function resetPassword(int $id, string $password, ?int $operatorId = null): void
    {
        $user = $this->findOrFail($id);

        if ($operatorId !== null) {
            $this->assertCanManage($operatorId, $user);
        }

        $this->passwordPolicy->validate($password, (string) $user->username);

        $user->password = password_hash($password, PASSWORD_BCRYPT);
        $user->password_changed_at = date('Y-m-d H:i:s');
        $user->save();

        // 管理员重置密码意味着账号可能已被接管，直接踢掉所有会话
        $this->tokenService->revokeAllForUser($id);

        $this->notificationService->push(
            $id,
            '登录密码已被管理员重置',
            '你的登录密码已由管理员重置，所有设备均已强制下线，请使用新密码重新登录。',
            Notification::TYPE_SECURITY,
            Notification::LEVEL_WARNING,
            '/profile'
        );
    }

    public function delete(int $id, int $operatorId): void
    {
        $user = $this->findOrFail($id);

        $this->assertCanManage($operatorId, $user);
        $this->assertNotSelf($id, $operatorId, '不能删除自己的账号');
        $this->assertNotLastAdmin($user, '不能删除最后一个可用管理员');

        Db::transaction(static function () use ($id) {
            User::query()->where('id', $id)->delete();
            // 消息跟着人走：账号删了，留着孤儿消息只会占表
            Notification::query()->where('user_id', $id)->delete();
        });

        $this->tokenService->revokeAllForUser($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function format(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => (string) $user->username,
            'nickname' => (string) $user->nickname,
            'email' => (string) $user->email,
            'role' => (string) $user->role,
            'dept_id' => $user->dept_id !== null ? (int) $user->dept_id : null,
            'status' => (int) $user->status,
            'totp_enabled' => (int) $user->totp_enabled === 1,
            'last_login_at' => $user->last_login_at?->toDateTimeString(),
            'created_at' => $user->created_at?->toDateTimeString(),
        ];
    }

    private function findOrFail(int $id): User
    {
        /** @var null|User $user */
        $user = User::query()->find($id);
        if ($user === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '用户不存在或已被删除');
        }

        return $user;
    }

    private function assertRoleExists(string $role): void
    {
        if (! Role::query()->where('code', $role)->exists()) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '所选角色不存在');
        }
    }

    private function assertNotSelf(int $id, int $operatorId, string $message): void
    {
        if ($id === $operatorId) {
            throw new BusinessException(ApiCode::FAILED, $message);
        }
    }

    /**
     * 数据权限守卫：操作者只能动自己范围内的用户。
     * 操作者不存在（系统任务/测试里传的假 ID）时不做限制。
     */
    private function assertCanManage(int $operatorId, User $target): void
    {
        if ($operatorId <= 0) {
            return;
        }

        /** @var null|User $operator */
        $operator = User::query()->find($operatorId);
        if ($operator === null) {
            return;
        }

        if (! $this->dataScope->allows($operator, $target)) {
            throw new BusinessException(ApiCode::FORBIDDEN, '无权操作该用户：不在你的数据权限范围内');
        }
    }

    /**
     * 目标用户是「当前可用的管理员」时，确认动完之后还剩至少一个可用管理员。
     */
    private function assertNotLastAdmin(User $user, string $message): void
    {
        if ((string) $user->role !== 'admin' || ! $user->isEnabled()) {
            return;
        }

        $remaining = (int) User::query()
            ->where('role', 'admin')
            ->where('status', 1)
            ->where('id', '<>', (int) $user->id)
            ->count();

        if ($remaining < 1) {
            throw new BusinessException(ApiCode::FAILED, $message);
        }
    }
}
