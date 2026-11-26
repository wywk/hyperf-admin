<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('departments')) {
            Schema::create('departments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('parent_id')->default(0)->comment('上级部门，0=顶级');
                $table->string('name', 64)->comment('部门名称');
                $table->string('code', 32)->unique()->comment('部门标识');
                $table->string('leader', 32)->default('')->comment('负责人');
                $table->string('phone', 20)->default('')->comment('联系电话');
                $table->integer('sort')->default(0)->comment('排序，越小越靠前');
                $table->unsignedTinyInteger('status')->default(1)->comment('1=启用 0=停用');
                // 物化路径：'/1/3/8/'，查下级用 LIKE 前缀，避免递归查询
                $table->string('path', 255)->default('')->comment('层级路径');
                $table->datetimes();

                $table->index('parent_id');
                $table->index('path');
                $table->charset = 'utf8mb4';
                $table->collation = 'utf8mb4_unicode_ci';
                $table->comment('部门');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'dept_id')) {
                $table->unsignedBigInteger('dept_id')->nullable()->comment('所属部门')->after('role');
                $table->index('dept_id');
            }
        });

        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'data_scope')) {
                $table->string('data_scope', 32)->default('all')
                    ->comment('数据范围：all/dept/dept_and_children/self')->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'dept_id')) {
                $table->dropColumn('dept_id');
            }
        });

        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'data_scope')) {
                $table->dropColumn('data_scope');
            }
        });

        Schema::dropIfExists('departments');
    }
};
