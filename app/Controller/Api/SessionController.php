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
use App\Controller\Concerns\AuthorizesRequests;
use App\Service\SessionService;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 在线会话管理：查看当前登录的会话、单个踢下线、按用户全部踢下线。
 */
class SessionController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected SessionService $sessionService)
    {
    }

    /**
     * GET /api/sessions.
     */
    #[SwGet(path: '/api/sessions', operationId: 'sessionIndex', summary: '在线会话列表', tag: '在线会话',
        desc: '基于 Redis 会话表，返回每个登录会话的用户、IP、浏览器、登录时间与最后活跃时间，当前会话置顶。需要 session.view 权限。',
        params: [
            new SwQuery('keyword', desc: '用户名 / 昵称模糊匹配'),
            new SwQuery('only_online', type: 'integer', desc: '1=只看最近 15 分钟活跃的会话'),
        ],
        responses: [new SwResp(desc: '会话列表', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'total', type: 'integer'),
                new OA\Property(property: 'online', type: 'integer', description: '在线会话数'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
            ]),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('session.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'keyword' => 'nullable|string|max:64',
            'only_online' => 'nullable|integer|in:0,1',
        ])->validate();

        return ApiResponse::success($this->sessionService->listSessions(
            isset($data['keyword']) ? (string) $data['keyword'] : null,
            (int) ($data['only_online'] ?? 0) === 1,
            $this->currentFamilyId()
        ));
    }

    /**
     * DELETE /api/sessions/{userId}/{familyId}.
     */
    #[SwDelete(path: '/api/sessions/{userId}/{familyId}', operationId: 'sessionKick', summary: '踢掉单个会话', tag: '在线会话',
        desc: '吊销该次登录签发的整条凭证链，对方下一次请求即返回 401。需要 session.kick 权限。',
        responses: [new SwResp(desc: '已踢下线')]
    )]
    public function kick(int $userId, string $familyId): ResponseInterface
    {
        $this->authorize('session.kick');

        $this->sessionService->kick($userId, $familyId);

        return ApiResponse::success(null, '该会话已下线');
    }

    /**
     * DELETE /api/sessions/user/{userId}.
     */
    #[SwDelete(path: '/api/sessions/user/{userId}', operationId: 'sessionKickUser', summary: '踢掉用户的全部会话', tag: '在线会话',
        desc: '吊销该用户所有登录会话；若操作的是自己，会保留当前会话。需要 session.kick 权限。',
        responses: [new SwResp(desc: '已下线')]
    )]
    public function kickUser(int $userId): ResponseInterface
    {
        $this->authorize('session.kick');

        $count = $this->sessionService->kickAll($userId, $this->currentFamilyId());

        return ApiResponse::success(['kicked' => $count], sprintf('已下线 %d 个会话', $count));
    }

    /**
     * 当前请求所属的会话家族，用来在列表里标记「当前设备」以及踢全部时保留自己。
     */
    private function currentFamilyId(): string
    {
        $payload = Context::get('token_payload');

        return is_array($payload) ? (string) ($payload['fid'] ?? '') : '';
    }
}
