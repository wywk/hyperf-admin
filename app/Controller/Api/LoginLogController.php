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

namespace App\Controller\Api;

use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Service\LoginLogService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 登录审计日志查询（需登录）。
 */
class LoginLogController extends AbstractController
{
    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected LoginLogService $loginLogService)
    {
    }

    /**
     * GET /api/auth/login-logs.
     */
    #[OA\Get(
        path: '/api/auth/login-logs',
        operationId: 'loginLogIndex',
        summary: '登录审计日志分页',
        description: '登录成功/失败/退出等审计事件流水，支持多条件过滤。',
        tags: ['登录审计'],
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, description: '页码，默认 1',
                schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'page_size', in: 'query', required: false, description: '每页条数，默认 20',
                schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
            new OA\Parameter(name: 'event', in: 'query', required: false, description: '事件类型，如 login/logout/login_failed',
                schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'username', in: 'query', required: false, description: '用户名（模糊匹配）',
                schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'user_id', in: 'query', required: false, description: '用户 ID',
                schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'ip', in: 'query', required: false, description: '登录 IP（精确匹配）',
                schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: '200',
                description: '分页结果：{total, page, page_size, items[]}，item 含 user_id/username/event/ip/user_agent/created_at',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'total', type: 'integer'),
                            new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                        ]),
                    ]
                )
            ),
            new OA\Response(response: '401', ref: '#/components/responses/Unauthorized'),
        ]
    )]
    public function index(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
            'event' => 'nullable|string|max:32',
            'username' => 'nullable|string|max:64',
            'user_id' => 'nullable|integer|min:1',
            'ip' => 'nullable|string|max:45',
        ], [
            'page.integer' => 'page 必须是整数',
            'page.min' => 'page 最小为 1',
            'page_size.between' => 'page_size 取值范围 1-100',
        ])->validate();

        $filters = array_filter([
            'event' => $data['event'] ?? null,
            'username' => $data['username'] ?? null,
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
            'ip' => $data['ip'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        return ApiResponse::success($this->loginLogService->paginate(
            $filters,
            max(1, (int) ($data['page'] ?? 1)),
            min(100, max(1, (int) ($data['page_size'] ?? 20)))
        ));
    }
}
