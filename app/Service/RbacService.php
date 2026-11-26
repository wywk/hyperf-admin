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

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\Permission;
use App\Model\Role;
use App\Model\User;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use Throwable;

/**
 * 角色 / 权限（树形：菜单 + 按钮）的读写与解析。
 *
 * 权限是一棵树：type=menu 的节点驱动前端侧边栏与路由守卫，type=button 的节点
 * 挂在菜单下，对应页面内操作（前端 v-permission、后端 authorize 用按钮码判断）。
 * 角色通过 role_permissions 直接持有节点 ID；授权树勾选时前端会带上勾选/半选的
 * 全部节点 ID，后端不做父子推导。
 *
 * 角色权限码的解析结果按角色缓存到 Redis；任何写操作都会主动失效缓存。
 */
class RbacService
{
    private const CACHE_PREFIX = 'rbac:role:';

    private const CACHE_TTL = 300;

    public function __construct(protected Redis $redis)
    {
    }

    /**
     * 角色实际拥有的权限码（菜单码 + 按钮码）。Redis 不可用时自动退化为查库。
     *
     * @return array<int, string>
     */
    public function permissionsForRole(string $roleCode): array
    {
        $roleCode = $roleCode !== '' ? $roleCode : 'user';
        $cacheKey = self::CACHE_PREFIX . $roleCode;

        try {
            $cached = $this->redis->get($cacheKey);
            if (is_string($cached)) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return array_values(array_map('strval', $decoded));
                }
            }
        } catch (Throwable) {
            // 缓存只是加速手段，读不到就直接落库
        }

        /** @var null|Role $role */
        $role = Role::query()->where('code', $roleCode)->first();
        $codes = $role === null ? [] : $this->codesForRoleId((int) $role->id);

        try {
            $this->redis->setex($cacheKey, self::CACHE_TTL, (string) json_encode($codes, JSON_UNESCAPED_UNICODE));
        } catch (Throwable) {
        }

        return $codes;
    }

    /**
     * 角色可见的菜单树（含目录），供登录信息 / me 接口返回，前端直接渲染侧边栏。
     *
     * 规则：勾选了菜单本身或其任一后代（按钮也算）则该菜单可见；
     * 父级目录即使没勾，只要下面有可见子菜单也会带出来（否则子菜单无法到达）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function menuTreeForRole(string $roleCode): array
    {
        $roleCode = $roleCode !== '' ? $roleCode : 'user';

        /** @var null|Role $role */
        $role = Role::query()->where('code', $roleCode)->first();
        if ($role === null) {
            return [];
        }

        $grantedIds = Db::table('role_permissions')
            ->where('role_id', (int) $role->id)
            ->pluck('permission_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($grantedIds === []) {
            return [];
        }
        $granted = array_fill_keys($grantedIds, true);

        /** @var array<int, Permission> $menus */
        $menus = Permission::query()
            ->where('type', Permission::TYPE_MENU)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->keyBy(static fn (Permission $row) => (int) $row->id)
            ->all();

        $childrenMap = [];
        foreach ($menus as $menu) {
            $childrenMap[(int) $menu->parent_id][] = (int) $menu->id;
        }

        // 先自底向上标记哪些菜单该展示，再自顶向下拼树
        $visible = [];
        $resolveVisibility = static function (int $id) use (&$resolveVisibility, &$visible, $granted, $childrenMap): bool {
            if (array_key_exists($id, $visible)) {
                return $visible[$id];
            }
            $visible[$id] = isset($granted[$id]);
            foreach ($childrenMap[$id] ?? [] as $childId) {
                if ($resolveVisibility($childId)) {
                    $visible[$id] = true;
                }
            }

            return $visible[$id];
        };

        $tree = [];
        foreach ($childrenMap[0] ?? [] as $rootId) {
            if ($resolveVisibility($rootId)) {
                $tree[] = $this->buildMenuNode($menus, $childrenMap, $rootId, $visible);
            }
        }

        return $tree;
    }

    /**
     * 全量权限树。keyword 非空时退化为平铺的过滤结果（树表按关键字过滤意义不大）。
     *
     * @return array{items: array<int, array<string, mixed>>, flat: bool}
     */
    public function permissionTree(?string $keyword = null): array
    {
        $roleCounts = $this->roleCountByPermission();

        if ($keyword !== null && $keyword !== '') {
            $needle = '%' . $keyword . '%';
            $rows = Permission::query()
                ->where(static function ($q) use ($needle) {
                    $q->where('code', 'like', $needle)->orWhere('name', 'like', $needle);
                })
                ->orderBy('sort')
                ->orderBy('id')
                ->get();

            $items = [];
            foreach ($rows as $row) {
                /* @var Permission $row */
                $items[] = $this->formatPermission($row) + ['role_count' => $roleCounts[(int) $row->id] ?? 0];
            }

            return ['items' => $items, 'flat' => true];
        }

        $rows = Permission::query()->orderBy('sort')->orderBy('id')->get();
        $nodes = [];
        $childrenMap = [];
        foreach ($rows as $row) {
            /* @var Permission $row */
            $id = (int) $row->id;
            $nodes[$id] = $this->formatPermission($row) + ['role_count' => $roleCounts[$id] ?? 0, 'children' => []];
            $childrenMap[(int) $row->parent_id][] = $id;
        }

        $attach = static function (int $parentId) use (&$attach, &$nodes, $childrenMap): array {
            $list = [];
            foreach ($childrenMap[$parentId] ?? [] as $id) {
                $nodes[$id]['children'] = $attach($id);
                $list[] = $nodes[$id];
            }

            return $list;
        };

        return ['items' => $attach(0), 'flat' => false];
    }

    /**
     * @param array{parent_id?: int, type: string, code: string, name: string, path?: string, icon?: string, visible?: int, sort?: int, description?: string} $data
     * @return array<string, mixed>
     */
    public function createPermission(array $data): array
    {
        $parentId = (int) ($data['parent_id'] ?? 0);
        $this->assertParentValid($parentId, $data['type'], null);

        $permission = Permission::query()->create([
            'parent_id' => $parentId,
            'type' => $data['type'],
            'code' => $data['code'],
            'name' => $data['name'],
            'path' => (string) ($data['path'] ?? ''),
            'icon' => (string) ($data['icon'] ?? ''),
            'visible' => (int) ($data['visible'] ?? 1),
            'is_system' => 0,
            'sort' => (int) ($data['sort'] ?? 0),
            'description' => (string) ($data['description'] ?? ''),
        ]);

        // 新增权限默认授予系统角色，避免超管在新权限上线后立刻失去入口
        $systemRoleIds = Role::query()->where('is_system', 1)->pluck('id')->map(static fn ($id) => (int) $id)->all();
        foreach ($systemRoleIds as $roleId) {
            Db::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => (int) $permission->id,
            ]);
        }

        $this->flushCache();

        return $this->formatPermission($permission) + ['role_count' => count($systemRoleIds)];
    }

    /**
     * @param array{parent_id?: int, code?: string, name?: string, path?: string, icon?: string, visible?: int, sort?: int, description?: string} $data
     * @return array<string, mixed>
     */
    public function updatePermission(int $id, array $data): array
    {
        /** @var null|Permission $permission */
        $permission = Permission::query()->find($id);
        if ($permission === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '权限不存在或已被删除');
        }

        if (isset($data['parent_id'])) {
            $parentId = (int) $data['parent_id'];
            if ($parentId !== (int) $permission->parent_id) {
                $this->assertParentValid($parentId, (string) $permission->type, $permission);
            }
            $permission->parent_id = $parentId;
        }

        if (isset($data['code']) && $data['code'] !== $permission->code) {
            if ($permission->isSystem()) {
                throw new BusinessException(ApiCode::FAILED, '内置权限不允许修改权限码');
            }
            $permission->code = $data['code'];
        }

        if (isset($data['name']) && $data['name'] !== '') {
            $permission->name = $data['name'];
        }
        if (isset($data['path'])) {
            $permission->path = (string) $data['path'];
        }
        if (isset($data['icon'])) {
            $permission->icon = (string) $data['icon'];
        }
        if (isset($data['visible'])) {
            $permission->visible = (int) $data['visible'];
        }
        if (isset($data['sort'])) {
            $permission->sort = (int) $data['sort'];
        }
        if (array_key_exists('description', $data)) {
            $permission->description = (string) $data['description'];
        }

        $permission->save();
        $this->flushCache();

        return $this->formatPermission($permission) + ['role_count' => $this->roleCountByPermission()[$id] ?? 0];
    }

    public function deletePermission(int $id): void
    {
        /** @var null|Permission $permission */
        $permission = Permission::query()->find($id);
        if ($permission === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '权限不存在或已被删除');
        }

        if ($permission->isSystem()) {
            throw new BusinessException(ApiCode::FAILED, '内置权限不允许删除');
        }

        $childCount = (int) Permission::query()->where('parent_id', $id)->count();
        if ($childCount > 0) {
            throw new BusinessException(ApiCode::FAILED, sprintf('该节点下还有 %d 个子权限，请先删除子权限', $childCount));
        }

        Db::transaction(static function () use ($id) {
            Db::table('role_permissions')->where('permission_id', $id)->delete();
            Permission::query()->where('id', $id)->delete();
        });

        $this->flushCache();
    }

    /**
     * @return array{total: int, items: array<int, array<string, mixed>>}
     */
    public function listRoles(): array
    {
        $rows = Role::query()->orderByDesc('is_system')->orderBy('sort')->orderBy('id')->get();

        $userCounts = Db::table('users')
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role')
            ->all();

        $permissionCounts = Db::table('role_permissions')
            ->selectRaw('role_id, count(*) as aggregate')
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id')
            ->all();

        $items = [];
        foreach ($rows as $row) {
            /* @var Role $row */
            $items[] = $this->formatRole($row) + [
                'user_count' => (int) ($userCounts[$row->code] ?? 0),
                'permission_count' => (int) ($permissionCounts[(int) $row->id] ?? 0),
            ];
        }

        return ['total' => count($items), 'items' => $items];
    }

    /**
     * @param array{code: string, name: string, description?: string, sort?: int, data_scope?: string} $data
     * @return array<string, mixed>
     */
    public function createRole(array $data): array
    {
        $role = Role::query()->create([
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'is_system' => 0,
            'sort' => (int) ($data['sort'] ?? 0),
            'data_scope' => $data['data_scope'] ?? 'all',
        ]);

        $this->flushCache();

        return $this->formatRole($role) + ['user_count' => 0, 'permission_count' => 0];
    }

    /**
     * @param array{code?: string, name?: string, description?: string, sort?: int, data_scope?: string} $data
     * @return array<string, mixed>
     */
    public function updateRole(int $id, array $data): array
    {
        /** @var null|Role $role */
        $role = Role::query()->find($id);
        if ($role === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '角色不存在或已被删除');
        }

        $oldCode = (string) $role->code;

        if (isset($data['code']) && $data['code'] !== $oldCode) {
            if ($role->isSystem()) {
                throw new BusinessException(ApiCode::FAILED, '内置角色的标识不允许修改');
            }
            $role->code = $data['code'];
        }

        if (isset($data['name'])) {
            $role->name = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $role->description = (string) $data['description'];
        }
        if (isset($data['sort'])) {
            $role->sort = (int) $data['sort'];
        }
        if (isset($data['data_scope']) && $data['data_scope'] !== '') {
            $role->data_scope = $data['data_scope'];
        }

        Db::transaction(function () use ($role, $oldCode) {
            $role->save();

            // 角色标识是 users.role 的取值，必须同步，否则老用户会瞬间失去全部权限
            if ((string) $role->code !== $oldCode) {
                User::query()->where('role', $oldCode)->update(['role' => (string) $role->code]);
            }
        });

        $this->flushCache();

        $userCount = (int) User::query()->where('role', (string) $role->code)->count();
        $permissionCount = (int) Db::table('role_permissions')->where('role_id', $id)->count();

        return $this->formatRole($role) + ['user_count' => $userCount, 'permission_count' => $permissionCount];
    }

    public function deleteRole(int $id): void
    {
        /** @var null|Role $role */
        $role = Role::query()->find($id);
        if ($role === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '角色不存在或已被删除');
        }

        if ($role->isSystem()) {
            throw new BusinessException(ApiCode::FAILED, '内置角色不允许删除');
        }

        $userCount = (int) User::query()->where('role', (string) $role->code)->count();
        if ($userCount > 0) {
            throw new BusinessException(ApiCode::FAILED, sprintf('该角色下还有 %d 个用户，请先把他们切换到其他角色', $userCount));
        }

        Db::transaction(static function () use ($id) {
            Db::table('role_permissions')->where('role_id', $id)->delete();
            Role::query()->where('id', $id)->delete();
        });

        $this->flushCache();
    }

    /**
     * @return array<int, int>
     */
    public function rolePermissionIds(int $roleId): array
    {
        if (Role::query()->find($roleId) === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '角色不存在或已被删除');
        }

        return Db::table('role_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 覆盖式同步角色的权限（前端提交勾选 + 半选的全部节点 ID）。
     *
     * @param array<int, int|string> $permissionIds
     * @return array<int, int>
     */
    public function syncRolePermissions(int $roleId, array $permissionIds): array
    {
        /** @var null|Role $role */
        $role = Role::query()->find($roleId);
        if ($role === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '角色不存在或已被删除');
        }

        $ids = array_values(array_unique(array_map('intval', $permissionIds)));
        if ($ids !== []) {
            $existing = Permission::query()->whereIn('id', $ids)->pluck('id')->map(static fn ($id) => (int) $id)->all();
            if (count($existing) !== count($ids)) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, '提交的权限中包含已删除的项，请刷新后重试');
            }
        }

        // 系统角色始终保留内置权限，防止误操作把管理入口全部关掉、再也改不回来
        if ($role->isSystem()) {
            $systemPermissionIds = Permission::query()
                ->where('is_system', 1)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();
            $ids = array_values(array_unique(array_merge($ids, $systemPermissionIds)));
        }

        Db::transaction(static function () use ($roleId, $ids) {
            Db::table('role_permissions')->where('role_id', $roleId)->delete();
            foreach ($ids as $permissionId) {
                Db::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        });

        $this->flushCache();

        return $ids;
    }

    /**
     * 按钮只能挂在菜单下；菜单可以挂在根或其他菜单下。
     * 移动节点时还要保证不会把自己挪到自己的后代下面形成环。
     */
    private function assertParentValid(int $parentId, string $type, ?Permission $self): void
    {
        if ($parentId === 0) {
            if ($type === Permission::TYPE_BUTTON) {
                throw new BusinessException(ApiCode::FAILED, '按钮权限必须挂在某个菜单下');
            }

            return;
        }

        /** @var null|Permission $parent */
        $parent = Permission::query()->find($parentId);
        if ($parent === null) {
            throw new BusinessException(ApiCode::FAILED, '上级菜单不存在或已被删除');
        }

        if (! $parent->isMenu()) {
            throw new BusinessException(ApiCode::FAILED, '上级只能是菜单节点，按钮下不能再挂子节点');
        }

        if ($self !== null) {
            // 从 parent 往上找：途中撞到自己说明 parent 在 self 的子树里，会成环
            $cursor = $parent;
            while ($cursor !== null && (int) $cursor->id !== (int) $self->id) {
                if ((int) $cursor->parent_id === 0) {
                    break;
                }
                /** @var null|Permission $next */
                $next = Permission::query()->find((int) $cursor->parent_id);
                $cursor = $next;
            }

            if ($cursor !== null && (int) $cursor->id === (int) $self->id) {
                throw new BusinessException(ApiCode::FAILED, '不能把权限移动到它自己的子级下面');
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function codesForRoleId(int $roleId): array
    {
        $permissionIds = Db::table('role_permissions')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($permissionIds === []) {
            return [];
        }

        return Permission::query()
            ->whereIn('id', $permissionIds)
            ->orderBy('sort')
            ->orderBy('id')
            ->pluck('code')
            ->map(static fn ($code) => (string) $code)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function roleCountByPermission(): array
    {
        return Db::table('role_permissions')
            ->selectRaw('permission_id, count(*) as aggregate')
            ->groupBy('permission_id')
            ->pluck('aggregate', 'permission_id')
            ->map(static fn ($count) => (int) $count)
            ->all();
    }

    /**
     * @param array<int, Permission> $menus
     * @param array<int, array<int, int>> $childrenMap
     * @param array<int, bool> $visible
     * @return array<string, mixed>
     */
    private function buildMenuNode(array $menus, array $childrenMap, int $id, array $visible): array
    {
        /* @var Permission $menu */
        $menu = $menus[$id];
        $children = [];
        foreach ($childrenMap[$id] ?? [] as $childId) {
            if ($visible[$childId] ?? false) {
                $children[] = $this->buildMenuNode($menus, $childrenMap, $childId, $visible);
            }
        }

        return [
            'id' => $id,
            'code' => (string) $menu->code,
            'name' => (string) $menu->name,
            'path' => (string) $menu->path,
            'icon' => (string) $menu->icon,
            'sort' => (int) $menu->sort,
            'children' => $children,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPermission(Permission $permission): array
    {
        return [
            'id' => (int) $permission->id,
            'parent_id' => (int) $permission->parent_id,
            'type' => (string) $permission->type,
            'code' => (string) $permission->code,
            'name' => (string) $permission->name,
            'path' => (string) $permission->path,
            'icon' => (string) $permission->icon,
            'visible' => (int) $permission->visible,
            'is_system' => (int) $permission->is_system,
            'sort' => (int) $permission->sort,
            'description' => (string) $permission->description,
            'created_at' => $permission->created_at?->toDateTimeString(),
            'updated_at' => $permission->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRole(Role $role): array
    {
        return [
            'id' => (int) $role->id,
            'code' => (string) $role->code,
            'name' => (string) $role->name,
            'description' => (string) $role->description,
            'data_scope' => (string) ($role->data_scope ?: 'all'),
            'is_system' => (int) $role->is_system,
            'sort' => (int) $role->sort,
            'created_at' => $role->created_at?->toDateTimeString(),
        ];
    }

    private function flushCache(): void
    {
        try {
            $codes = Role::query()->pluck('code')->map(static fn ($code) => (string) $code)->all();
            $keys = array_map(static fn (string $code) => self::CACHE_PREFIX . $code, $codes);
            if ($keys !== []) {
                $this->redis->del(...$keys);
            }
        } catch (Throwable) {
        }
    }
}
