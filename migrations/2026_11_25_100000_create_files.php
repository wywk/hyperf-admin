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
        Schema::create('files', function (Blueprint $table) {
            $table->bigIncrements('id')->comment('文件 ID');
            $table->string('name', 255)->comment('原始文件名');
            $table->string('path', 255)->comment('存储相对路径，如 20261125/xxx-uuid.png');
            $table->string('driver', 32)->default('local')->comment('存储驱动，如 local');
            $table->string('mime', 128)->default('')->comment('MIME 类型');
            $table->string('ext', 16)->default('')->comment('扩展名（小写，不含点）');
            $table->unsignedBigInteger('size')->default(0)->comment('文件大小（字节）');
            $table->char('sha1', 40)->default('')->comment('内容 SHA1，可用于去重');
            $table->unsignedBigInteger('user_id')->default(0)->comment('上传者用户 ID，0=未知');
            $table->timestamps();
            $table->comment('文件管理：上传文件的元数据，物理文件由存储驱动管理');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
