<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Service;

use App\Model\Department;
use App\Model\Role;
use App\Model\User;
use Hyperf\Database\Model\Builder;

/**
 * 数据权限：在「功能权限」之外，控制用户能看到/操作哪些数据。
 *
 * 四种范围（配在角色上）：
 * - all                全部数据
 * - dept               本部门
 * - dept_and_children  本部门及下级部门（物化路径一次查出）
 * - self               仅本人
 *
 * 约定：没有部门却配了 dept 类范围的账号，自动退化成「仅本人」，
 * 避免出现「因为忘配部门所以能看全公司」这种反向事故。
 */
class DataScopeService
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_DEPT = 'dept';

    public const SCOPE_DEPT_CHILDREN = 'dept_and_children';

    public const SCOPE_SELF = 'self';

    public const SCOPES = [
        self::SCOPE_ALL => '全部数据',
        self::SCOPE_DEPT => '本部门',
        self::SCOPE_DEPT_CHILDREN => '本部门及下级',
        self::SCOPE_SELF => '仅本人',
    ];

    public function __construct(protected DepartmentService $departments)
    {
    }

    public function scopeOf(User $user): string
    {
        $scope = (string) Role::query()->where('code', (string) $user->role)->value('data_scope');

        return array_key_exists($scope, self::SCOPES) ? $scope : self::SCOPE_ALL;
    }

    /**
     * 给用户查询加数据范围条件（users 表：id / dept_id）。
     */
    public function applyToUsers(Builder $query, User $user): void
    {
        $scope = $this->scopeOf($user);

        if ($scope === self::SCOPE_ALL) {
            return;
        }

        if ($scope === self::SCOPE_SELF) {
            $query->where('id', (int) $user->id);

            return;
        }

        $deptId = $user->dept_id !== null ? (int) $user->dept_id : 0;
        $deptIds = $scope === self::SCOPE_DEPT_CHILDREN
            ? $this->departments->descendantAndSelfIds($deptId)
            : ($deptId > 0 ? [$deptId] : []);

        if ($deptIds === []) {
            // 没有部门就没有「本部门数据」，退化到仅本人
            $query->where('id', (int) $user->id);

            return;
        }

        $query->whereIn('dept_id', $deptIds);
    }

    /**
     * 当前用户可见的用户 ID；null 表示不受限（全部）。
     *
     * @return null|array<int, int>
     */
    public function visibleUserIds(User $user): ?array
    {
        if ($this->scopeOf($user) === self::SCOPE_ALL) {
            return null;
        }

        $query = User::query()->select('id');
        $this->applyToUsers($query, $user);

        /** @var array<int, int> $ids */
        $ids = $query->pluck('id')->all();

        return array_map('intval', $ids);
    }

    /**
     * 操作者是否有权管理目标用户（改资料/停用/重置密码/删除之前调用）。
     */
    public function allows(User $operator, User $target): bool
    {
        if ($this->scopeOf($operator) === self::SCOPE_ALL) {
            return true;
        }

        if ((int) $operator->id === (int) $target->id) {
            return true;
        }

        $ids = $this->visibleUserIds($operator);

        return $ids !== null && in_array((int) $target->id, $ids, true);
    }

    /**
     * 给前端展示的当前数据范围说明。
     *
     * @return array{scope: string, scope_text: string, dept_id: null|int, dept_name: string}
     */
    public function describe(User $user): array
    {
        $scope = $this->scopeOf($user);
        $deptId = $user->dept_id !== null ? (int) $user->dept_id : null;

        return [
            'scope' => $scope,
            'scope_text' => self::SCOPES[$scope] ?? $scope,
            'dept_id' => $deptId,
            'dept_name' => $deptId !== null
                ? (string) Department::query()->where('id', $deptId)->value('name')
                : '',
        ];
    }
}
