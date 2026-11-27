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
use App\Service\CaptchaService;
use App\Service\TotpService;
use App\Support\ClientIp;
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

class AuthController extends AbstractController
{
    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(
        protected AuthService $authService,
        protected CaptchaService $captchaService,
        protected TotpService $totpService
    ) {
    }

    /**
     * POST /api/auth/login.
     */
    #[SwPost(
        path: '/api/auth/login',
        operationId: 'authLogin',
        summary: '登录获取双 Token',
        tag: '认证',
        auth: false,
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
            'captcha_id' => 'nullable|string|max:64',
            'captcha_code' => 'nullable|string|max:8',
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
            $this->userAgent(),
            isset($data['captcha_id']) ? (string) $data['captcha_id'] : null,
            isset($data['captcha_code']) ? (string) $data['captcha_code'] : null
        );

        return ApiResponse::success($result, ($result['requires_totp'] ?? false) ? '请输入动态验证码' : '登录成功');
    }

    /**
     * GET /api/auth/captcha：按需签发图形验证码.
     */
    #[SwGet(
        path: '/api/auth/captcha',
        operationId: 'authCaptcha',
        summary: '获取图形验证码',
        tag: '认证',
        auth: false,
        desc: '先判断当前用户名/IP 是否需要验证码：不需要时只返回 required=false；需要时返回 SVG 图片（data URI）与一次性 captcha_id。',
        params: [new SwQuery('username', desc: '正在登录的用户名，用于判断失败次数')],
        responses: [new SwResp(desc: '{required, captcha_id?, image?, expires_in?}')]
    )]
    public function captcha(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'username' => 'nullable|string|max:64',
        ])->validate();

        $ip = ClientIp::fromRequest($this->request);
        $username = (string) ($data['username'] ?? '');

        if (! $this->captchaService->isRequired($ip, $username)) {
            return ApiResponse::success(['required' => false]);
        }

        return ApiResponse::success(['required' => true] + $this->captchaService->issue());
    }

    /**
     * POST /api/auth/totp/verify：两步验证第二步，换正式 token.
     */
    #[SwPost(
        path: '/api/auth/totp/verify',
        operationId: 'authTotpVerify',
        summary: '校验动态验证码完成登录',
        tag: '认证',
        auth: false,
        desc: '用登录接口返回的 totp_token + 6 位动态码换取正式 token 对；凭证 5 分钟内有效且只能用一次。',
        errors: [401, 422],
        body: new SwBody(
            props: [
                new SwProp('totp_token', desc: '登录第一步返回的一次性凭证'),
                new SwProp('code', desc: '认证器 App 中的 6 位动态码'),
            ],
            requiredProps: ['totp_token', 'code'],
        ),
        responses: [new SwResp(desc: '登录成功，返回 token 对与用户信息')]
    )]
    public function verifyTotp(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'totp_token' => 'required|string|max:128',
            'code' => 'required|string|size:6',
        ], [
            'totp_token.required' => '缺少二次验证凭证，请重新登录',
            'code.required' => '请输入动态验证码',
            'code.size' => '动态验证码为 6 位数字',
        ])->validate();

        return ApiResponse::success($this->authService->verifyTotpLogin(
            (string) $data['totp_token'],
            (string) $data['code'],
            ClientIp::fromRequest($this->request),
            $this->userAgent()
        ), '登录成功');
    }

    /**
     * POST /api/auth/totp/setup：生成待绑定的 TOTP 密钥.
     */
    #[SwPost(
        path: '/api/auth/totp/setup',
        operationId: 'authTotpSetup',
        summary: '生成两步验证密钥',
        tag: '认证',
        desc: '返回 Base32 密钥与 otpauth 链接（前端可自行渲染二维码）。密钥 10 分钟内有效，验证通过后才写入账号。',
        responses: [new SwResp(desc: '{secret, otpauth_url, issuer}')]
    )]
    public function setupTotp(): ResponseInterface
    {
        return ApiResponse::success($this->totpService->startSetup($this->currentUser()));
    }

    /**
     * POST /api/auth/totp/enable：校验动态码后开启两步验证.
     */
    #[SwPost(
        path: '/api/auth/totp/enable',
        operationId: 'authTotpEnable',
        summary: '开启两步验证',
        tag: '认证',
        desc: '校验动态码通过后把密钥写入账号，下次登录需要两步验证。',
        errors: [422],
        body: new SwBody(props: [new SwProp('code', desc: '6 位动态码')], requiredProps: ['code']),
        responses: [new SwResp(desc: '已开启')]
    )]
    public function enableTotp(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'code' => 'required|string|size:6',
        ], ['code.size' => '动态验证码为 6 位数字'])->validate();

        $this->totpService->enable($this->currentUser(), (string) $data['code']);

        return ApiResponse::success(null, '两步验证已开启');
    }

    /**
     * POST /api/auth/totp/disable：校验动态码后关闭两步验证.
     */
    #[SwPost(
        path: '/api/auth/totp/disable',
        operationId: 'authTotpDisable',
        summary: '关闭两步验证',
        tag: '认证',
        desc: '需要提供当前动态码，避免 token 被盗后直接关掉二次验证。',
        errors: [422],
        body: new SwBody(props: [new SwProp('code', desc: '6 位动态码')], requiredProps: ['code']),
        responses: [new SwResp(desc: '已关闭')]
    )]
    public function disableTotp(): ResponseInterface
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'code' => 'required|string|size:6',
        ], ['code.size' => '动态验证码为 6 位数字'])->validate();

        $this->totpService->disable($this->currentUser(), (string) $data['code']);

        return ApiResponse::success(null, '两步验证已关闭');
    }

    /**
     * POST /api/auth/refresh：用 refresh token 换新的 token 对.
     */
    #[SwPost(
        path: '/api/auth/refresh',
        operationId: 'authRefresh',
        summary: '刷新 access_token',
        tag: '认证',
        auth: false,
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
    #[SwPost(
        path: '/api/auth/logout',
        operationId: 'authLogout',
        summary: '退出登录',
        tag: '认证',
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
    #[SwGet(
        path: '/api/auth/me',
        operationId: 'authMe',
        summary: '当前用户信息',
        tag: '认证',
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

    /**
     * POST /api/auth/password：修改自己的密码（需登录）.
     */
    #[SwPost(
        path: '/api/auth/password',
        operationId: 'authChangePassword',
        summary: '修改自己的密码',
        tag: '认证',
        desc: '校验旧密码后设置新密码；修改成功后除当前设备外的其他登录会话立即失效。',
        errors: [401, 422],
        body: new SwBody(
            props: [
                new SwProp('old_password', desc: '当前密码'),
                new SwProp('new_password', desc: '新密码，6-64 位'),
            ],
            requiredProps: ['old_password', 'new_password'],
        ),
        responses: [new SwResp(desc: '密码已更新')]
    )]
    public function changePassword(): ResponseInterface
    {
        $user = Context::get('user');
        $payload = Context::get('token_payload');
        if (! $user instanceof User) {
            throw new BusinessException(ApiCode::UNAUTHORIZED);
        }

        $data = $this->validatorFactory->make($this->request->all(), [
            'old_password' => 'required|string|between:6,64',
            'new_password' => 'required|string|between:6,64',
        ], [
            'old_password.required' => '请输入当前密码',
            'old_password.between' => '当前密码长度不正确',
            'new_password.required' => '请输入新密码',
            'new_password.between' => '新密码长度需为 6-64 个字符',
        ])->validate();

        $this->authService->changePassword(
            $user,
            (string) $data['old_password'],
            (string) $data['new_password'],
            is_array($payload) ? $payload : []
        );

        return ApiResponse::success(null, '密码已更新，其他设备的登录已失效');
    }

    private function currentUser(): User
    {
        $user = Context::get('user');
        if (! $user instanceof User) {
            throw new BusinessException(ApiCode::UNAUTHORIZED);
        }

        return $user;
    }

    private function userAgent(): string
    {
        return mb_substr($this->request->getHeaderLine('User-Agent'), 0, 255);
    }
}
