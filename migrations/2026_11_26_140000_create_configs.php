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
        Schema::create('configs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('group', 32)->default('basic')->comment('分组标识，如 security/audit');
            $table->string('key', 64)->unique()->comment('配置键，读取时用它');
            $table->text('value')->nullable()->comment('配置值，统一按 type 存储');
            $table->string('type', 16)->default('string')->comment('string/number/boolean/text/json');
            $table->string('name', 64)->comment('展示名称');
            $table->string('description', 255)->default('')->comment('说明');
            $table->unsignedInteger('sort')->default(0)->comment('同组内排序');
            $table->unsignedTinyInteger('is_system')->default(0)->comment('1=内置，不允许删除');
            $table->datetimes();

            $table->index('group');
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->comment('系统配置表');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configs');
    }
};
