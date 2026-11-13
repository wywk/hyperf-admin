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
use App\Model\User;
use Hyperf\Database\Seeders\Seeder;

use function Hyperf\Support\env;

/**
 * 开发/测试用初始账号。重复执行会重置密码，生产环境请勿随意执行。
 *
 * php bin/hyperf.php db:seed
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->upsertUser(
            'admin',
            (string) env('SEED_ADMIN_PASSWORD', 'admin123456'),
            '管理员',
            'admin@example.com',
            1,
            'admin'
        );

        // 普通角色：只有 dashboard.view / profile.view，用来验证前端菜单与 403
        $this->upsertUser(
            'demo',
            (string) env('SEED_DEMO_PASSWORD', 'demo123456'),
            '演示账号',
            'demo@example.com',
            1,
            'user'
        );

        // 用于验证「账号被禁用」分支
        $this->upsertUser(
            'disabled',
            (string) env('SEED_DISABLED_PASSWORD', 'disabled123456'),
            '已禁用账号',
            'disabled@example.com',
            0,
            'user'
        );
    }

    private function upsertUser(string $username, string $password, string $nickname, string $email, int $status, string $role): void
    {
        /** @var null|User $user */
        $user = User::query()->where('username', $username)->first();
        $user ??= new User();

        $user->username = $username;
        $user->password = password_hash($password, PASSWORD_BCRYPT);
        $user->nickname = $nickname;
        $user->email = $email;
        $user->status = $status;
        $user->role = $role;
        $user->save();
    }
}
