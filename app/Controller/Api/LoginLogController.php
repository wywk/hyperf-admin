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
