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
        Schema::create('crontab_tasks', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('定时任务 ID');
            $table->string('name', 64)->comment('任务名称');
            $table->string('handler', 128)->comment('任务处理类，实现 App\Crontab\TaskInterface');
            $table->string('rule', 64)->comment('执行规则，标准 cron 表达式（5 位）');
            $table->json('params')->nullable()->comment('任务参数，透传给 execute()');
            $table->unsignedTinyInteger('status')->default(1)->comment('1=启用 0=停用');
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->comment('连续失败次数');
            $table->timestamp('last_run_at')->nullable()->comment('最近一次执行时间');
            $table->timestamps();
            $table->unique('handler');
            $table->comment('定时任务：任务定义与调度状态');
        });

        Schema::create('crontab_logs', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('执行日志 ID');
            $table->unsignedBigInteger('task_id')->comment('所属任务 ID');
            $table->unsignedTinyInteger('status')->default(0)->comment('0=运行中 1=成功 2=失败');
            $table->text('output')->nullable()->comment('任务输出（execute 返回值或异常信息）');
            $table->unsignedInteger('cost_ms')->default(0)->comment('耗时（毫秒）');
            $table->timestamp('created_at')->nullable()->comment('执行时间');
            $table->index(['task_id', 'created_at']);
            $table->comment('定时任务：执行日志');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crontab_logs');
        Schema::dropIfExists('crontab_tasks');
    }
};
