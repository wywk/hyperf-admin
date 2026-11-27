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
        Schema::create('operation_logs', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('操作日志 ID');
            $table->unsignedBigInteger('user_id')->default(0)->comment('操作人用户 ID，0=未知');
            $table->string('username', 64)->default('')->comment('操作人用户名快照');
            $table->string('method', 10)->comment('请求方法，只记录 POST/PUT/DELETE');
            $table->string('path', 255)->comment('请求路径，如 /api/roles');
            $table->string('action', 128)->default('')->comment('路由名称/动作标识，如 App\Controller\Api\RoleController@store');
            $table->json('params')->nullable()->comment('请求参数（已脱敏，password/token 等敏感字段剔除）');
            $table->unsignedSmallInteger('status_code')->default(0)->comment('响应状态码');
            $table->unsignedInteger('cost_ms')->default(0)->comment('耗时（毫秒）');
            $table->string('ip', 45)->default('')->comment('客户端 IP');
            $table->string('user_agent', 512)->default('')->comment('User-Agent');
            $table->timestamp('created_at')->nullable()->comment('操作时间');
            $table->index(['user_id', 'created_at']);
            $table->index(['method', 'created_at']);
            $table->index('created_at');
            $table->comment('操作日志：登录用户对写操作（POST/PUT/DELETE）的审计流水');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_logs');
    }
};
