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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'password_changed_at')) {
                $table->dateTime('password_changed_at')->nullable()->comment('最近一次改密时间，用于密码有效期')->after('last_login_at');
            }
            if (! Schema::hasColumn('users', 'totp_secret')) {
                $table->string('totp_secret', 64)->default('')->comment('TOTP 密钥（Base32），为空表示未绑定')->after('password_changed_at');
            }
            if (! Schema::hasColumn('users', 'totp_enabled')) {
                $table->unsignedTinyInteger('totp_enabled')->default(0)->comment('1=已开启两步验证')->after('totp_secret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['password_changed_at', 'totp_secret', 'totp_enabled'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
