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

use Hyperf\Context\Context;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 为每个请求生成（或透传）X-Request-Id，写入协程上下文，
 * 统一响应体和日志都可以带上它，方便串联排查。
 */
class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = trim($request->getHeaderLine('X-Request-Id'));
        if ($requestId === '' || strlen($requestId) > 64) {
            $requestId = bin2hex(random_bytes(8));
        }

        Context::set('request_id', $requestId);

        $response = $handler->handle($request->withAttribute('request_id', $requestId));

        return $response->withHeader('X-Request-Id', $requestId);
    }
}
