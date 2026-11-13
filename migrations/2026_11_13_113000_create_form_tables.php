<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
use Hyperf\Database\Migrations\Migration;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->comment('表单设计器：表单定义');
            $table->bigIncrements('id');
            $table->string('name', 64)->comment('表单名称');
            $table->string('code', 64)->unique()->comment('表单标识，公开提交接口用它定位');
            $table->json('schema')->comment('JSON Schema：字段定义（设计器导出）');
            $table->string('description', 255)->default('')->comment('说明');
            $table->tinyInteger('status')->default(1)->comment('1=已发布 0=停用');
            $table->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->comment('表单提交数据');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('form_id')->comment('所属表单');
            $table->json('data')->comment('提交的数据');
            $table->string('client_ip', 45)->default('')->comment('提交者 IP');
            $table->dateTime('created_at');
            $table->index('form_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('forms');
    }
};
