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

class AddDiffColumnToOperationLogs extends Migration
{
    public function up(): void
    {
        Schema::table('operation_logs', function (Blueprint $table) {
            // 字段级变更快照：[{table,id,field,before,after}]；创建/删除类只有单侧
            $table->json('diff')->nullable()->after('params');
        });
    }

    public function down(): void
    {
        Schema::table('operation_logs', function (Blueprint $table) {
            $table->dropColumn('diff');
        });
    }
}
