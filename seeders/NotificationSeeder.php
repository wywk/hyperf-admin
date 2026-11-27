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
use App\Model\Notification;
use App\Model\User;
use Hyperf\Database\Seeders\Seeder;

/**
 * 演示用站内消息：只在管理员一条消息都没有时写入，避免每次 seed 都堆一份。
 */
class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = (int) User::query()->where('username', 'admin')->value('id');
        if ($adminId <= 0) {
            return;
        }

        if (Notification::query()->where('user_id', $adminId)->exists()) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $items = [
            [
                'title' => '欢迎使用消息中心',
                'content' => '定时任务失败、密码被重置、账号被停用等事件都会在这里通知你，右上角铃铛可以看到未读数。',
                'type' => Notification::TYPE_SYSTEM,
                'level' => Notification::LEVEL_SUCCESS,
                'link' => '',
                'is_read' => 0,
                'created_at' => date('Y-m-d H:i:s', time() - 60),
            ],
            [
                'title' => '安全提醒：登录密码策略已生效',
                'content' => '建议为管理员账号开启强密码，并定期在「个人中心」更换密码。',
                'type' => Notification::TYPE_SECURITY,
                'level' => Notification::LEVEL_INFO,
                'link' => '/profile',
                'is_read' => 1,
                'created_at' => date('Y-m-d H:i:s', time() - 3600),
            ],
        ];

        foreach ($items as $item) {
            Notification::query()->create($item + [
                'user_id' => $adminId,
                'read_at' => (int) $item['is_read'] === 1 ? $now : null,
            ]);
        }
    }
}
