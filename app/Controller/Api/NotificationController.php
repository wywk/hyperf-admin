<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Controller\Api;

use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Model\Notification;
use App\Service\NotificationService;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 站内消息：只处理「自己的」消息，全部接口仅需登录，不需要额外权限。
 */
class NotificationController extends AbstractController
{
    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected NotificationService $notificationService)
    {
    }

    /**
     * GET /api/notifications.
     */
    #[SwGet(path: '/api/notifications', operationId: 'notificationIndex', summary: '我的消息列表', tag: '消息中心', page: true,
        desc: '只返回当前登录用户的消息，按时间倒序；支持按已读状态与类型过滤。',
        params: [
            new SwQuery('is_read', type: 'integer', desc: '1=已读 0=未读，不传则全部'),
            new SwQuery('type', desc: '类型：system / crontab / account / security'),
        ],
        responses: [new SwResp(desc: '分页结果：{total, page, page_size, unread, items[]}', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'unread', type: 'integer'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
            ]),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
            'is_read' => 'nullable|integer|in:0,1',
            'type' => 'nullable|string|in:system,crontab,account,security',
        ], [
            'page_size.between' => 'page_size 取值范围 1-100',
        ])->validate();

        return ApiResponse::success($this->notificationService->paginate(
            $this->userId(),
            $data,
            (int) ($data['page'] ?? 1),
            (int) ($data['page_size'] ?? 20)
        ));
    }

    /**
     * GET /api/notifications/summary.
     */
    #[SwGet(path: '/api/notifications/summary', operationId: 'notificationSummary', summary: '未读汇总', tag: '消息中心',
        desc: '顶栏铃铛用：未读总数 + 各类型未读数，一次请求拿全。',
        responses: [new SwResp(desc: '{unread, total, by_type}', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'unread', type: 'integer'),
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'by_type', type: 'object'),
            ]),
        ])]
    )]
    public function summary(): ResponseInterface
    {
        return ApiResponse::success($this->notificationService->summary($this->userId()));
    }

    /**
     * POST /api/notifications/{id}/read.
     */
    #[SwPost(path: '/api/notifications/{id}/read', operationId: 'notificationRead', summary: '标记单条已读', tag: '消息中心',
        desc: '只能标记自己的消息；重复标记不报错。',
        responses: [new SwResp(desc: '已读')]
    )]
    public function read(int $id): ResponseInterface
    {
        $this->notificationService->markRead($this->userId(), $id);

        return ApiResponse::success(null, '已标记为已读');
    }

    /**
     * POST /api/notifications/read-all.
     */
    #[SwPost(path: '/api/notifications/read-all', operationId: 'notificationReadAll', summary: '全部标记已读', tag: '消息中心',
        desc: '把当前用户所有未读消息置为已读，返回影响条数。',
        responses: [new SwResp(desc: '已读条数')]
    )]
    public function readAll(): ResponseInterface
    {
        $count = $this->notificationService->markAllRead($this->userId());

        return ApiResponse::success(['updated' => $count], sprintf('已标记 %d 条为已读', $count));
    }

    /**
     * DELETE /api/notifications/read.
     */
    #[SwDelete(path: '/api/notifications/read', operationId: 'notificationClearRead', summary: '清空已读消息', tag: '消息中心',
        desc: '删除当前用户所有已读消息，未读消息不受影响。',
        responses: [new SwResp(desc: '删除条数')]
    )]
    public function clearRead(): ResponseInterface
    {
        $count = $this->notificationService->clearRead($this->userId());

        return ApiResponse::success(['deleted' => $count], sprintf('已清理 %d 条已读消息', $count));
    }

    /**
     * DELETE /api/notifications/{id}.
     */
    #[SwDelete(path: '/api/notifications/{id}', operationId: 'notificationDelete', summary: '删除单条消息', tag: '消息中心',
        desc: '只能删除自己的消息。',
        responses: [new SwResp(desc: '已删除')]
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->notificationService->destroy($this->userId(), $id);

        return ApiResponse::success(null, '消息已删除');
    }

    private function userId(): int
    {
        return (int) Context::get('user_id');
    }
}
