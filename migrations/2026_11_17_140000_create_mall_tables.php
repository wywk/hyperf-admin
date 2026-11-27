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

/*
 * 商城 demo：分类 / 商品 / 订单 / 订单明细。
 *
 * 表名不带前缀（统一前缀由 DB_PREFIX 自动拼接）。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mall_categories', function (Blueprint $table) {
            $table->comment('商城演示：商品分类');
            $table->bigIncrements('id');
            $table->string('name', 64)->comment('分类名称');
            $table->tinyInteger('sort')->default(0)->comment('排序，越小越靠前');
            $table->tinyInteger('status')->default(1)->comment('1=启用 0=停用');
            $table->string('description', 255)->default('')->comment('说明');
            $table->timestamps();
        });

        Schema::create('mall_goods', function (Blueprint $table) {
            $table->comment('商城演示：商品');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('category_id')->default(0)->comment('所属分类');
            $table->string('name', 128)->comment('商品名称');
            $table->string('cover', 255)->default('')->comment('封面图 URL');
            $table->decimal('price', 10, 2)->default(0)->comment('售价（元）');
            $table->integer('stock')->default(0)->comment('库存');
            $table->tinyInteger('status')->default(1)->comment('1=上架 0=下架');
            $table->string('summary', 255)->default('')->comment('商品简介');
            $table->timestamps();
            $table->index('category_id');
            $table->index('status');
        });

        Schema::create('mall_orders', function (Blueprint $table) {
            $table->comment('商城演示：订单');
            $table->bigIncrements('id');
            $table->string('order_no', 32)->unique()->comment('订单号');
            $table->unsignedBigInteger('user_id')->default(0)->comment('下单用户 ID');
            $table->string('receiver', 64)->default('')->comment('收货人');
            $table->string('phone', 20)->default('')->comment('联系电话');
            $table->string('address', 255)->default('')->comment('收货地址');
            $table->decimal('amount', 10, 2)->default(0)->comment('订单金额（元）');
            $table->tinyInteger('status')->default(0)->comment('0=待付款 1=已付款 2=已发货 3=已完成 4=已取消');
            $table->string('remark', 255)->default('')->comment('备注');
            $table->timestamps();
            $table->index('user_id');
            $table->index('status');
        });

        Schema::create('mall_order_items', function (Blueprint $table) {
            $table->comment('商城演示：订单明细');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->default(0)->comment('所属订单');
            $table->unsignedBigInteger('goods_id')->default(0)->comment('商品 ID');
            $table->string('goods_name', 128)->default('')->comment('下单时商品名称快照');
            $table->decimal('price', 10, 2)->default(0)->comment('成交单价（元）');
            $table->integer('quantity')->default(1)->comment('数量');
            $table->decimal('amount', 10, 2)->default(0)->comment('小计（元）');
            $table->timestamps();
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mall_order_items');
        Schema::dropIfExists('mall_orders');
        Schema::dropIfExists('mall_goods');
        Schema::dropIfExists('mall_categories');
    }
};
