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

/**
 * RBAC 表化：权限、角色、角色-权限关联。
 * 之前权限写在 config/autoload/rbac.php 里，只能改文件；表化之后可以后台增删改。
 * users.role 保留为角色 code，保持不变，避免影响已有登录逻辑。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 64)->unique()->comment('权限码，如 permission.manage');
            $table->string('name', 64)->comment('权限名称');
            $table->string('group_name', 64)->default('')->comment('分组，用于前端分组展示');
            $table->string('description', 255)->default('')->comment('说明');
            $table->tinyInteger('is_system')->default(0)->comment('1=内置权限，不可删除、不可改权限码');
            $table->integer('sort')->default(0)->comment('排序，越小越靠前');
            $table->timestamps();

            $table->index('group_name');
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->comment('权限');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 32)->unique()->comment('角色标识，users.role 存这个值');
            $table->string('name', 64)->comment('角色名称');
            $table->string('description', 255)->default('')->comment('说明');
            $table->tinyInteger('is_system')->default(0)->comment('1=系统内置，不可删除');
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->comment('角色');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');

            $table->unique(['role_id', 'permission_id']);
            $table->index('permission_id');
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->comment('角色-权限关联');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
