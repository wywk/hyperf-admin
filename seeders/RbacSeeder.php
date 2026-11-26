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
use App\Model\Permission;
use App\Model\Role;
use Hyperf\Database\Seeders\Seeder;
use Hyperf\DbConnection\Db;

/**
 * RBAC 基础数据：权限树（菜单 + 按钮）与内置角色。
 *
 * 幂等，可重复执行；不会覆盖你在后台改过的名称/排序。
 * php bin/hyperf.php db:seed
 */
class RbacSeeder extends Seeder
{
    /**
     * 权限树定义：code => [父code(空=根), 类型, 名称, 路由, 图标, 排序]。
     *
     * 菜单节点驱动前端侧边栏与路由守卫（路由 meta.permission = 菜单 code）；
     * 按钮节点挂在自己所属菜单下，对应页面内的操作（v-permission / 后端 authorize）。
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int}>
     */
    private const TREE = [
        'dashboard' => ['', 'menu', '仪表盘', 'dashboard', 'Odometer', 10],

        'profile' => ['', 'menu', '个人中心', 'profile', 'User', 20],

        'system' => ['', 'menu', '系统管理', 'system', 'Setting', 30],

        'permission' => ['system', 'menu', '权限管理', 'system/permissions', 'Key', 10],
        'permission.view' => ['permission', 'button', '查看权限', '', '', 10],
        'permission.add' => ['permission', 'button', '新增权限', '', '', 20],
        'permission.edit' => ['permission', 'button', '编辑权限', '', '', 30],
        'permission.delete' => ['permission', 'button', '删除权限', '', '', 40],

        'user' => ['system', 'menu', '用户管理', 'system/users', 'Avatar', 15],
        'user.view' => ['user', 'button', '查看用户', '', '', 10],
        'user.add' => ['user', 'button', '新增用户', '', '', 20],
        'user.edit' => ['user', 'button', '编辑用户', '', '', 30],
        'user.delete' => ['user', 'button', '删除用户', '', '', 40],
        'user.reset' => ['user', 'button', '重置密码', '', '', 50],

        'dept' => ['system', 'menu', '部门管理', 'system/departments', 'OfficeBuilding', 18],
        'dept.view' => ['dept', 'button', '查看部门', '', '', 10],
        'dept.add' => ['dept', 'button', '新增部门', '', '', 20],
        'dept.edit' => ['dept', 'button', '编辑部门', '', '', 30],
        'dept.delete' => ['dept', 'button', '删除部门', '', '', 40],

        'role' => ['system', 'menu', '角色管理', 'system/roles', 'UserFilled', 20],
        'role.view' => ['role', 'button', '查看角色', '', '', 10],
        'role.add' => ['role', 'button', '新增角色', '', '', 20],
        'role.edit' => ['role', 'button', '编辑角色', '', '', 30],
        'role.delete' => ['role', 'button', '删除角色', '', '', 40],
        'role.assign' => ['role', 'button', '分配权限', '', '', 50],

        'session' => ['system', 'menu', '在线会话', 'system/sessions', 'Monitor', 25],
        'session.view' => ['session', 'button', '查看会话', '', '', 10],
        'session.kick' => ['session', 'button', '踢下线', '', '', 20],

        'audit' => ['system', 'menu', '登录审计', 'audit', 'Tickets', 30],
        'audit.view' => ['audit', 'button', '查看审计日志', '', '', 10],

        'file' => ['system', 'menu', '文件管理', 'system/files', 'Files', 40],
        'file.view' => ['file', 'button', '查看文件', '', '', 10],
        'file.upload' => ['file', 'button', '上传文件', '', '', 20],
        'file.delete' => ['file', 'button', '删除文件', '', '', 30],

        'dict' => ['system', 'menu', '数据字典', 'system/dicts', 'Collection', 50],
        'dict.view' => ['dict', 'button', '查看字典', '', '', 10],
        'dict.add' => ['dict', 'button', '新增字典', '', '', 20],
        'dict.edit' => ['dict', 'button', '编辑字典', '', '', 30],
        'dict.delete' => ['dict', 'button', '删除字典', '', '', 40],

        'config' => ['system', 'menu', '系统配置', 'system/configs', 'SetUp', 55],
        'config.view' => ['config', 'button', '查看配置', '', '', 10],
        'config.edit' => ['config', 'button', '修改配置', '', '', 20],

        'oplog' => ['system', 'menu', '操作日志', 'system/oplogs', 'Document', 60],
        'oplog.view' => ['oplog', 'button', '查看操作日志', '', '', 10],

        'crontab' => ['system', 'menu', '定时任务', 'system/crontabs', 'Timer', 70],
        'crontab.view' => ['crontab', 'button', '查看任务', '', '', 10],
        'crontab.add' => ['crontab', 'button', '新增任务', '', '', 20],
        'crontab.edit' => ['crontab', 'button', '编辑任务', '', '', 30],
        'crontab.delete' => ['crontab', 'button', '删除任务', '', '', 40],
        'crontab.run' => ['crontab', 'button', '手动执行', '', '', 50],

        'jobs' => ['system', 'menu', '任务中心', 'system/jobs', 'List', 58],
        'jobs.view' => ['jobs', 'button', '查看全部任务', '', '', 10],

        'tool' => ['', 'menu', '工具开发', 'tool', 'SetUp', 40],

        'form' => ['tool', 'menu', '表单管理', 'tool/forms', 'EditPen', 10],
        'form.view' => ['form', 'button', '查看表单', '', '', 10],
        'form.add' => ['form', 'button', '新建表单', '', '', 20],
        'form.edit' => ['form', 'button', '编辑表单', '', '', 30],
        'form.delete' => ['form', 'button', '删除表单', '', '', 40],

        'gen' => ['tool', 'menu', '代码生成', 'tool/gen', 'MagicStick', 20],
        'gen.view' => ['gen', 'button', '查看数据表', '', '', 10],
        'gen.create' => ['gen', 'button', '生成代码', '', '', 20],
    ];

    public function run(): void
    {
        $idsByCode = [];
        foreach (self::TREE as $code => [$parentCode, $type, $name, $path, $icon, $sort]) {
            /** @var null|Permission $permission */
            $permission = Permission::query()->where('code', $code)->first() ?? new Permission();
            $permission->parent_id = $parentCode !== '' ? (int) $idsByCode[$parentCode] : 0;
            $permission->type = $type;
            $permission->code = $code;
            $permission->name = $permission->name ?: $name;
            $permission->path = $permission->path ?: $path;
            $permission->icon = $permission->icon ?: $icon;
            $permission->visible = 1;
            $permission->is_system = 1;
            $permission->sort = $permission->sort ?: $sort;
            $permission->save();

            $idsByCode[$code] = (int) $permission->id;
        }

        $admin = $this->upsertRole('admin', '管理员', '拥有全部权限，内置角色不可删除', 1, 10);
        $user = $this->upsertRole('user', '普通用户', '仅可访问基础页面', 0, 20);

        // 管理员：整棵权限树 + 生成器等途径创建的全部权限（超管语义：始终拥有所有权限）
        $allPermissionIds = Permission::query()->pluck('id')->all();
        $this->sync($admin, $allPermissionIds);

        // 普通用户：只给基础页面，用来验证菜单过滤与 403
        $basicCodes = ['dashboard', 'profile'];
        $this->sync($user, array_map(static fn (string $code) => $idsByCode[$code], $basicCodes));
    }

    private function upsertRole(string $code, string $name, string $description, int $isSystem, int $sort): Role
    {
        /** @var Role $role */
        $role = Role::query()->where('code', $code)->first() ?? new Role();
        $role->code = $code;
        $role->name = $role->name ?: $name;
        $role->description = $role->description ?: $description;
        $role->is_system = $isSystem;
        $role->sort = $role->sort ?: $sort;
        $role->save();

        return $role;
    }

    /**
     * @param array<int, int> $permissionIds
     */
    private function sync(Role $role, array $permissionIds): void
    {
        Db::table('role_permissions')->where('role_id', $role->id)->delete();

        foreach ($permissionIds as $permissionId) {
            Db::table('role_permissions')->insertOrIgnore([
                'role_id' => (int) $role->id,
                'permission_id' => $permissionId,
            ]);
        }
    }
}
