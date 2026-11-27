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
        Schema::create('notifications', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->comment('接收者用户 ID');
            $table->string('type', 32)->default('system')->comment('类型：system/crontab/account/security');
            $table->string('level', 16)->default('info')->comment('级别：info/success/warning/error');
            $table->string('title', 128)->comment('标题');
            $table->string('content', 500)->default('')->comment('正文，纯文本');
            $table->string('link', 255)->default('')->comment('点击后跳转的前端路由');
            $table->unsignedTinyInteger('is_read')->default(0)->comment('1=已读');
            $table->dateTime('read_at')->nullable()->comment('阅读时间');
            $table->datetimes();

            // 未读数与「我的消息」列表都按 user_id 走，未读用组合索引直接命中
            $table->index(['user_id', 'is_read', 'id']);
            $table->index(['user_id', 'id']);
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->comment('站内消息');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
