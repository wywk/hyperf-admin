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
 * 树形模块演示表：生成器识别 parent_id 后自动产出树形列表页。
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('demo_categories')) {
            return;
        }

        Schema::create('demo_categories', static function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->default(0)->comment('上级分类（0=顶级）');
            $table->string('name', 64)->comment('分类名称');
            $table->unsignedInteger('sort')->default(0)->comment('排序');
            $table->unsignedTinyInteger('status')->default(1)->comment('1=启用 0=停用');
            $table->timestamps();

            $table->index('parent_id');
            $table->comment('商品分类（树形演示）');
        });

        // 三级示例数据，让树形效果开箱可见
        $now = date('Y-m-d H:i:s');
        Db::table('demo_categories')->insert([
            ['parent_id' => 0, 'name' => '数码家电', 'sort' => 10, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['parent_id' => 0, 'name' => '食品生鲜', 'sort' => 20, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['parent_id' => 0, 'name' => '服饰鞋包', 'sort' => 30, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['parent_id' => 1, 'name' => '手机通讯', 'sort' => 10, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['parent_id' => 1, 'name' => '电脑办公', 'sort' => 20, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['parent_id' => 2, 'name' => '水果', 'sort' => 10, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['parent_id' => 6, 'name' => '热带水果', 'sort' => 10, 'status' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_categories');
    }
};
