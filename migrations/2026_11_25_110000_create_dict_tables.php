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
use Hyperf\DbConnection\Db;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dict_types', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('字典类型 ID');
            $table->string('name', 64)->comment('类型名称，如 订单状态');
            $table->string('code', 64)->comment('类型编码，如 order_status，业务按此取字典');
            $table->string('description', 255)->default('')->comment('描述');
            $table->unsignedInteger('sort')->default(0)->comment('排序，越小越靠前');
            $table->unsignedTinyInteger('status')->default(1)->comment('1=启用 0=停用');
            $table->timestamps();
            $table->unique('code');
            $table->comment('数据字典：字典类型');
        });

        Schema::create('dict_items', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('字典项 ID');
            $table->unsignedBigInteger('type_id')->comment('所属字典类型 ID');
            $table->string('label', 64)->comment('显示文本，如 已支付');
            $table->string('value', 64)->comment('存储值，如 paid');
            $table->unsignedInteger('sort')->default(0)->comment('排序，越小越靠前');
            $table->unsignedTinyInteger('status')->default(1)->comment('1=启用 0=停用（停用后不下发前端）');
            $table->string('remark', 255)->default('')->comment('备注');
            $table->timestamps();
            $table->unique(['type_id', 'value']);
            $table->comment('数据字典：字典项');
        });

        // 预置示例字典，方便新用户理解用法（幂等）
        Db::table('dict_types')->insertOrIgnore([
            'name' => '通用状态', 'code' => 'common_status', 'description' => '示例：通用的启用/停用状态',
            'sort' => 0, 'status' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $typeId = Db::table('dict_types')->where('code', 'common_status')->value('id');
        if ($typeId !== null) {
            $now = date('Y-m-d H:i:s');
            Db::table('dict_items')->insertOrIgnore([
                ['type_id' => $typeId, 'label' => '启用', 'value' => '1', 'sort' => 10, 'status' => 1, 'remark' => '', 'created_at' => $now, 'updated_at' => $now],
                ['type_id' => $typeId, 'label' => '停用', 'value' => '0', 'sort' => 20, 'status' => 1, 'remark' => '', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dict_items');
        Schema::dropIfExists('dict_types');
    }
};
