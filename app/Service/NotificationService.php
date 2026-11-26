<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\Notification;
use App\Model\User;
use Hyperf\Contract\StdoutLoggerInterface;
use Throwable;

/**
 * 站内消息：一条消息对应一个接收者，群发按用户展开。
 *
 * 设计上把消息当「旁路能力」：推送失败只写日志，绝不影响触发它的业务
 * （重置密码、任务告警这类操作不能因为消息表出问题而回滚）。
 */
class NotificationService
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    public function push(
        int $userId,
        string $title,
        string $content = '',
        string $type = Notification::TYPE_SYSTEM,
        string $level = Notification::LEVEL_INFO,
        string $link = ''
    ): void {
        $this->pushMany([$userId], $title, $content, $type, $level, $link);
    }

    /**
     * @param array<int, int> $userIds
     */
    public function pushMany(
        array $userIds,
        string $title,
        string $content = '',
        string $type = Notification::TYPE_SYSTEM,
        string $level = Notification::LEVEL_INFO,
        string $link = ''
    ): void {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn (int $id): bool => $id > 0)));
        if ($userIds === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = [
                'user_id' => $userId,
                'type' => $type,
                'level' => $level,
                'title' => mb_substr($title, 0, 128),
                'content' => mb_substr($content, 0, 500),
                'link' => mb_substr($link, 0, 255),
                'is_read' => 0,
                'read_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        try {
            Notification::query()->insert($rows);
        } catch (Throwable $e) {
            $this->logger->warning('站内消息写入失败：' . $e->getMessage());
        }
    }

    /**
     * 按角色群发（例如「定时任务失败」只通知管理员）。
     */
    public function pushToRole(
        string $role,
        string $title,
        string $content = '',
        string $type = Notification::TYPE_SYSTEM,
        string $level = Notification::LEVEL_INFO,
        string $link = ''
    ): void {
        /** @var array<int, int> $ids */
        $ids = User::query()->where('role', $role)->where('status', 1)->pluck('id')->all();
        $this->pushMany($ids, $title, $content, $type, $level, $link);
    }

    /**
     * @param array{is_read?: null|int|string, type?: null|string} $filters
     * @return array{total: int, page: int, page_size: int, unread: int, items: array<int, array<string, mixed>>}
     */
    public function paginate(int $userId, array $filters, int $page, int $pageSize): array
    {
        $query = Notification::query()->where('user_id', $userId);

        if (isset($filters['is_read']) && $filters['is_read'] !== null && $filters['is_read'] !== '') {
            $query->where('is_read', (int) $filters['is_read'] === 1 ? 1 : 0);
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        $total = (int) $query->count();

        /** @var array<int, Notification> $rows */
        $rows = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get()
            ->all();

        $items = array_map(fn (Notification $row): array => $this->format($row), $rows);

        return [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'unread' => $this->unreadCount($userId),
            'items' => $items,
        ];
    }

    /**
     * 顶栏铃铛用：未读数 + 各类型未读数，一次请求拿全。
     *
     * @return array{unread: int, total: int, by_type: array<string, int>}
     */
    public function summary(int $userId): array
    {
        /** @var array<int, object{type: string, aggregate: int}> $rows */
        $rows = Notification::query()
            ->selectRaw('type, count(*) as aggregate')
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->groupBy('type')
            ->get()
            ->all();

        $byType = [];
        $unread = 0;
        foreach ($rows as $row) {
            $count = (int) $row->aggregate;
            $byType[(string) $row->type] = $count;
            $unread += $count;
        }

        return [
            'unread' => $unread,
            'total' => (int) Notification::query()->where('user_id', $userId)->count(),
            'by_type' => $byType,
        ];
    }

    public function unreadCount(int $userId): int
    {
        return (int) Notification::query()->where('user_id', $userId)->where('is_read', 0)->count();
    }

    public function markRead(int $userId, int $id): void
    {
        $notification = $this->findOrFail($userId, $id);
        if ((int) $notification->is_read === 1) {
            return;
        }

        $notification->is_read = 1;
        $notification->read_at = date('Y-m-d H:i:s');
        $notification->save();
    }

    /**
     * @return int 本次标记的条数
     */
    public function markAllRead(int $userId): int
    {
        return (int) Notification::query()
            ->where('user_id', $userId)
            ->where('is_read', 0)
            ->update(['is_read' => 1, 'read_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function destroy(int $userId, int $id): void
    {
        $notification = $this->findOrFail($userId, $id);
        $notification->delete();
    }

    /**
     * 清空已读消息（未读的一条不动）。
     *
     * @return int 删除条数
     */
    public function clearRead(int $userId): int
    {
        return (int) Notification::query()->where('user_id', $userId)->where('is_read', 1)->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Notification $notification): array
    {
        return [
            'id' => (int) $notification->id,
            'type' => (string) $notification->type,
            'type_text' => Notification::TYPES[(string) $notification->type] ?? (string) $notification->type,
            'level' => (string) $notification->level,
            'title' => (string) $notification->title,
            'content' => (string) $notification->content,
            'link' => (string) $notification->link,
            'is_read' => (int) $notification->is_read === 1,
            'read_at' => $notification->read_at?->toDateTimeString(),
            'created_at' => $notification->created_at?->toDateTimeString(),
        ];
    }

    private function findOrFail(int $userId, int $id): Notification
    {
        /** @var null|Notification $notification */
        $notification = Notification::query()->where('user_id', $userId)->find($id);
        if ($notification === null) {
            // 不区分「不存在」和「不是你的」，避免探测别人的消息 ID
            throw new BusinessException(ApiCode::NOT_FOUND, '消息不存在或已被删除');
        }

        return $notification;
    }
}
