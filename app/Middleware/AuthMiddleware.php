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

namespace App\Middleware;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\User;
use App\Service\TokenService;
use App\Support\Jwt;
use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * 需要登录的接口挂载此中间件：校验 Authorization: Bearer <token>，
 * 通过后把 user 与 token payload 写入协程上下文与 request attribute。
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(protected TokenService $tokenService)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (! preg_match('/^Bearer\s+(?<token>\S+)$/i', $header, $matches)) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '未登录，请先登录');
        }

        try {
            // 只接受 access token，refresh token 不能直接访问业务接口
            $payload = Jwt::parse($matches['token'], Jwt::TYPE_ACCESS);
        } catch (Throwable) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '登录状态无效或已过期，请重新登录');
        }

        if ($this->tokenService->isRevoked((string) ($payload['jti'] ?? ''))) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '登录状态已失效，请重新登录');
        }

        // 整条凭证链被吊销（登出、检测到 refresh 重放）时，签发时未被拉黑的 access token 也一并失效
        if ($this->tokenService->isFamilyRevoked((string) ($payload['fid'] ?? ''))) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '登录状态已失效，请重新登录');
        }

        $userId = (int) ($payload['sub'] ?? 0);
        if ($userId <= 0) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '登录凭证不合法，请重新登录');
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '用户不存在或已被删除');
        }

        if (! $user->isEnabled()) {
            throw new BusinessException(ApiCode::FORBIDDEN, '账号已被禁用');
        }

        Context::set('user_id', $userId);
        Context::set('user', $user);
        Context::set('token_payload', $payload);

        return $handler->handle(
            $request->withAttribute('user_id', $userId)
                ->withAttribute('user', $user)
                ->withAttribute('token_payload', $payload)
        );
    }
}
