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
/*
 * 最小可用的 RBAC：一个用户一个角色，角色映射到权限码列表，权限码由前端用于菜单/按钮控制。
 * 现在放在配置里，等业务复杂了再换成 roles / permissions / role_permissions 三张表，
 * 只需替换 App\Support\Permission 的实现，接口返回结构不变。
 */
return [
    // 全部权限码及说明（'*' 代表全部）
    'permissions' => [
        'dashboard.view' => '查看仪表盘',
        'profile.view' => '查看个人资料',
        'audit.view' => '查看登录审计',
        'user.manage' => '用户管理',
    ],

    // 角色 => 权限码
    'roles' => [
        'admin' => ['*'],
        'user' => ['dashboard.view', 'profile.view'],
    ],
];
