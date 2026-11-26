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

/**
 * 异步任务表：队列任务的业务侧台账（状态流转 / 结果文件 / 错误信息），
 * 真正的消息体在 Redis 队列里，这里只存「谁提交了什么、跑到哪一步」。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('type', 64)->comment('任务类型，如 export.login_logs');
            $table->tinyInteger('status')->default(0)->comment('0=排队中 1=执行中 2=已完成 3=失败');
            $table->json('payload')->nullable()->comment('任务参数（导出筛选条件等）');
            $table->unsignedInteger('result_file_id')->nullable()->comment('结果文件 ID（files 表）');
            $table->string('error', 500)->default('')->comment('失败原因');
            $table->unsignedTinyInteger('attempts')->default(0)->comment('已尝试次数');
            $table->unsignedInteger('user_id')->nullable()->comment('提交人');
            $table->unsignedInteger('duration_ms')->nullable()->comment('执行耗时（毫秒）');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('finished_at')->nullable()->comment('完成/失败时间');

            $table->index(['user_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
