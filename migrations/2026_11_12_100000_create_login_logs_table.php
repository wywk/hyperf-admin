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
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('login_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户 ID，账号不存在或已删除时为空');
            $table->string('username', 64)->default('')->comment('本次尝试登录的用户名');
            $table->string('event', 32)->comment('事件类型：login_success/login_failed/login_locked/login_disabled/logout/refresh_replay');
            $table->string('ip', 45)->default('')->comment('客户端 IP');
            $table->string('user_agent', 512)->default('')->comment('User-Agent');
            $table->string('message', 255)->default('')->comment('说明信息');
            $table->dateTime('created_at')->useCurrent()->comment('发生时间');

            $table->index('user_id');
            $table->index('event');
            $table->index('created_at');
            $table->index(['ip', 'created_at']);

            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->comment('登录审计日志');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_logs');
    }
};
