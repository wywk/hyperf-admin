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

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mall_goods_skus', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('SKU ID');
            $table->unsignedBigInteger('goods_id')->comment('所属商品');
            $table->string('sku_code', 64)->comment('SKU 编码，如 IP15PM-BK-512');
            $table->string('specs', 191)->default('')->comment('规格描述，如 颜色:黑色;存储:512G');
            $table->decimal('price', 10, 2)->default(0)->comment('售价（元）');
            $table->integer('stock')->default(0)->comment('库存');
            $table->tinyInteger('status')->default(1)->comment('1=启用 0=停用');
            $table->timestamps();
            $table->comment('商城 SKU：商品规格（颜色/容量等）粒度的价格与库存');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mall_goods_skus');
    }
};
