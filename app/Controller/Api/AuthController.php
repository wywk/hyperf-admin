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

use App\Common\ApiCode;
use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Exception\BusinessException;
use App\Model\User;
use App\Service\AuthService;
use App\Support\ClientIp;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface;

class AuthController extends AbstractController
{
    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected AuthService $authService)
    {
    }

    /**
     * POST /api/auth/login.
     */
    public function login(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'username' => 'required|string|between:3,64',
            'password' => 'required|string|between:6,64',
        ], [
            'username.required' => '请输入用户名',
            'username.between' => '用户名长度需为 3-64 个字符',
            'password.required' => '请输入密码',
            'password.between' => '密码长度需为 6-64 个字符',
        ])->validate();

        $result = $this->authService->login(
            (string) $data['username'],
            (string) $data['password'],
            ClientIp::fromRequest($this->request),
            $this->userAgent()
        );

        return ApiResponse::success($result, '登录成功');
    }

    /**
     * POST /api/auth/refresh：用 refresh token 换新的 token 对.
     */
    public function refresh(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'refresh_token' => 'required|string',
        ], [
            'refresh_token.required' => '请提供 refresh_token',
        ])->validate();

        return ApiResponse::success(
            $this->authService->refresh(
                (string) $data['refresh_token'],
                ClientIp::fromRequest($this->request),
                $this->userAgent()
            ),
            '刷新成功'
        );
    }

    /**
     * POST /api/auth/logout（需登录）.
     */
    public function logout(): ResponseInterface
    {
        $payload = Context::get('token_payload');
        if (! is_array($payload)) {
            throw new BusinessException(ApiCode::UNAUTHORIZED);
        }

        $this->authService->logout($payload, ClientIp::fromRequest($this->request), $this->userAgent());

        return ApiResponse::success(null, '已退出登录');
    }

    /**
     * GET /api/auth/me（需登录）.
     */
    public function me(): ResponseInterface
    {
        $user = Context::get('user');
        if (! $user instanceof User) {
            throw new BusinessException(ApiCode::UNAUTHORIZED);
        }

        return ApiResponse::success($this->authService->profile($user));
    }

    private function userAgent(): string
    {
        return mb_substr($this->request->getHeaderLine('User-Agent'), 0, 255);
    }
}
