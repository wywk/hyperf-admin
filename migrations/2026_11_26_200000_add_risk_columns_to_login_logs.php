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

/**
 * 登录日志增加风控字段：风险等级 + 命中原因。
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('risk_level')->default(0)->comment('0=正常 1=低 2=中 3=高')->after('message');
            $table->string('risk_reasons', 255)->default('')->comment('命中原因，逗号分隔')->after('risk_level');
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::table('login_logs', function (Blueprint $table) {
            $table->dropIndex(['risk_level']);
            $table->dropColumn(['risk_level', 'risk_reasons']);
        });
    }
};
