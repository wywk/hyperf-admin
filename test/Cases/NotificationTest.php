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

namespace HyperfTest\Cases;

use App\Exception\BusinessException;
use App\Model\Notification;
use App\Model\User;
use App\Service\NotificationService;
use App\Service\UserService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class NotificationTest extends TestCase
{
    private const PREFIX = 'test_notify_';

    private NotificationService $notifications;

    private UserService $users;

    private int $ownerId;

    private int $otherId;

    protected function setUp(): void
    {
        parent::setUp();
        $container = ApplicationContext::getContainer();
        $this->notifications = $container->get(NotificationService::class);
        $this->users = $container->get(UserService::class);
        $this->cleanup();

        $this->ownerId = (int) $this->users->create([
            'username' => self::PREFIX . 'owner',
            'password' => 'pass123456',
            'role' => 'user',
        ])['id'];

        $this->otherId = (int) $this->users->create([
            'username' => self::PREFIX . 'other',
            'password' => 'pass123456',
            'role' => 'user',
        ])['id'];
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testCreateUserSendsWelcomeNotification(): void
    {
        // 新建账号会自动收到一条欢迎消息，这也是「事件联动」的最小验证
        $items = Notification::query()->where('user_id', $this->ownerId)->get()->all();
        $this->assertCount(1, $items);
        $this->assertStringContainsString('欢迎', (string) $items[0]->title);
        $this->assertSame(0, (int) $items[0]->is_read);
    }

    public function testPushAndSummary(): void
    {
        $this->notifications->push($this->ownerId, '任务失败', '内容 A', Notification::TYPE_CRONTAB, Notification::LEVEL_ERROR);
        $this->notifications->push($this->ownerId, '账号提醒', '内容 B', Notification::TYPE_ACCOUNT, Notification::LEVEL_WARNING);

        $summary = $this->notifications->summary($this->ownerId);
        // 欢迎消息 + 两条推送
        $this->assertSame(3, $summary['unread']);
        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['by_type'][Notification::TYPE_CRONTAB]);
        $this->assertSame(1, $summary['by_type'][Notification::TYPE_ACCOUNT]);

        $page = $this->notifications->paginate($this->ownerId, [], 1, 2);
        $this->assertSame(3, $page['total']);
        $this->assertSame(3, $page['unread']);
        $this->assertCount(2, $page['items']);
        // 倒序：最新的排最前
        $this->assertSame('账号提醒', $page['items'][0]['title']);
    }

    public function testFilterUnreadOnly(): void
    {
        $this->notifications->push($this->ownerId, '已读的消息');
        $first = $this->notifications->paginate($this->ownerId, [], 1, 20)['items'][0];

        $this->notifications->markRead($this->ownerId, (int) $first['id']);

        $unread = $this->notifications->paginate($this->ownerId, ['is_read' => 0], 1, 20);
        $this->assertSame(1, $unread['total']);
        $this->assertSame('欢迎加入 Hyperf Admin', $unread['items'][0]['title']);

        $read = $this->notifications->paginate($this->ownerId, ['is_read' => 1], 1, 20);
        $this->assertSame(1, $read['total']);
        $this->assertTrue($read['items'][0]['is_read']);
    }

    public function testCannotTouchOthersNotification(): void
    {
        $this->notifications->push($this->ownerId, '只属于 owner 的消息');
        $id = (int) Notification::query()->where('user_id', $this->ownerId)->value('id');

        try {
            $this->notifications->markRead($this->otherId, $id);
            $this->fail('不应该能标记别人的消息');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('不存在', $e->getMessage());
        }

        $this->expectException(BusinessException::class);
        $this->notifications->destroy($this->otherId, $id);
    }

    public function testMarkAllAndClearRead(): void
    {
        $this->notifications->push($this->ownerId, 'A');
        $this->notifications->push($this->ownerId, 'B');

        $updated = $this->notifications->markAllRead($this->ownerId);
        $this->assertSame(3, $updated);
        $this->assertSame(0, $this->notifications->unreadCount($this->ownerId));

        $this->notifications->push($this->ownerId, 'C');
        $deleted = $this->notifications->clearRead($this->ownerId);
        $this->assertSame(3, $deleted);
        // 清空已读不动未读
        $this->assertSame(1, $this->notifications->unreadCount($this->ownerId));
    }

    public function testHttpEndpointsOnlyReturnOwnMessages(): void
    {
        $this->notifications->push($this->otherId, 'other 的私信');

        $login = $this->json('/api/auth/login', [
            'username' => self::PREFIX . 'owner',
            'password' => 'pass123456',
        ]);
        $this->assertSame(0, $login['code']);
        $headers = ['Authorization' => 'Bearer ' . $login['data']['access_token']];

        $list = $this->get('/api/notifications', [], $headers);
        $this->assertSame(0, $list['code']);
        $titles = array_column($list['data']['items'], 'title');
        $this->assertNotContains('other 的私信', $titles);

        $summary = $this->get('/api/notifications/summary', [], $headers);
        $this->assertSame(1, $summary['data']['unread']);

        $readAll = $this->json('/api/notifications/read-all', [], $headers);
        $this->assertSame(0, $readAll['code']);
        $this->assertSame(1, $readAll['data']['updated']);
    }

    private function cleanup(): void
    {
        /** @var array<int, int> $ids */
        $ids = User::query()->where('username', 'like', self::PREFIX . '%')->pluck('id')->all();
        if ($ids !== []) {
            Notification::query()->whereIn('user_id', $ids)->delete();
            User::query()->whereIn('id', $ids)->delete();
        }
    }
}
