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
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwResp;
use OpenApi\Attributes as OA;
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
    #[SwPost(path: '/api/auth/login', operationId: 'authLogin', summary: '登录获取双 Token', tag: '认证', auth: false,
        desc: '校验用户名密码，签发 access_token（2 小时）与 refresh_token（30 天，含旋转与家族吊销）。',
        errors: [422],
        body: new SwBody(
            props: [
                new SwProp('username', desc: '用户名（3-64 字符）', example: 'admin'),
                new SwProp('password', desc: '密码（6-64 字符）', example: 'admin123456'),
            ],
            requiredProps: ['username', 'password'],
        ),
        responses: [new SwResp(desc: '登录成功，返回 token 对与用户信息', props: [
            new OA\Property(property: 'code', type: 'integer', example: 0),
            new OA\Property(property: 'message', type: 'string', example: '登录成功'),
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'access_token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string'),
                new OA\Property(property: 'expires_in', type: 'integer', example: 7200),
                new OA\Property(property: 'user', type: 'object'),
            ]),
        ])]
    )]
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
    #[SwPost(path: '/api/auth/refresh', operationId: 'authRefresh', summary: '刷新 access_token', tag: '认证', auth: false,
        desc: '用 refresh_token 换新 token 对；旧 refresh_token 被重放会触发整个家族吊销。',
        body: new SwBody(
            props: [new SwProp('refresh_token', desc: '登录时返回的刷新令牌')],
            requiredProps: ['refresh_token'],
        ),
        responses: [new SwResp(desc: '返回新的 token 对')]
    )]
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
    #[SwPost(path: '/api/auth/logout', operationId: 'authLogout', summary: '退出登录', tag: '认证',
        desc: '将当前 access_token（jti）加入黑名单。',
        errors: [401],
        responses: [new SwResp(desc: '已退出登录')]
    )]
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
    #[SwGet(path: '/api/auth/me', operationId: 'authMe', summary: '当前用户信息', tag: '认证',
        desc: '返回用户资料、角色、权限码集合与菜单树（侧边栏数据源）。',
        errors: [401],
        responses: [new SwResp(desc: '用户信息', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'user', type: 'object'),
                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'permissions', type: 'array', items: new OA\Items(type: 'string'), description: '权限码集合，如 mall_goods.edit'),
                new OA\Property(property: 'menus', type: 'array', items: new OA\Items(type: 'object'), description: '菜单树（type=menu 节点）'),
            ]),
        ])]
    )]
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
