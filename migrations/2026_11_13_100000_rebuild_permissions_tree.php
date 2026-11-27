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
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;

/*
 * 权限表重构为树形：菜单权限（menu）与按钮权限（button）通过 parent_id 组成一棵树。
 *
 * 旧表是平铺的权限码列表（靠 group_name 分组），无法表达「页面 → 按钮」的层级，
 * 侧边栏菜单也不能由权限数据驱动。新表 type=menu 的节点对应前端路由与侧边栏，
 * type=button 的节点挂在菜单下，对应页面内的操作权限（v-permission / 后端 authorize）。
 *
 * 注意：权限 ID 体系整体变化，role_permissions 旧关联一并清空，随后由 RbacSeeder 重建
 * admin（全部权限）与 user（基础页面）的授权，自建角色的授权需要重新勾选。
 */
return new class extends Migration {
    public function up(): void
    {
        Db::table('role_permissions')->delete();

        Schema::dropIfExists('permissions');
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('父节点，0=根节点');
            $table->string('type', 16)->default('button')->comment('类型：menu=菜单/目录，button=按钮');
            $table->string('code', 64)->unique()->comment('权限码：菜单用于路由守卫，按钮用于 v-permission/authorize');
            $table->string('name', 64)->comment('名称，如「权限管理」「新增权限」');
            $table->string('path', 128)->default('')->comment('路由路径（仅菜单），如 system/permissions');
            $table->string('icon', 64)->default('')->comment('菜单图标（仅菜单），Element Plus 图标组件名');
            $table->tinyInteger('visible')->default(1)->comment('是否在侧边栏显示（仅菜单）');
            $table->tinyInteger('is_system')->default(0)->comment('1=内置节点，不可删除、不可改权限码');
            $table->integer('sort')->default(0)->comment('排序，越小越靠前');
            $table->string('description', 255)->default('')->comment('说明');
            $table->timestamps();

            $table->index('parent_id');
            $table->index('type');
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->comment('权限树（菜单+按钮）');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
