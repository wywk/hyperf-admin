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

use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function Hyperf\Config\config;

/**
 * 跨域处理：OPTIONS 预检直接返回 204，其余请求在响应上补 CORS 头。
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response = ApplicationContext::getContainer()
                ->get(HyperfResponse::class)
                ->withStatus(204);

            return $this->decorate($response, $request);
        }

        return $this->decorate($handler->handle($request), $request);
    }

    private function decorate(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $allowOrigins = (array) config('cors.allow_origins', ['*']);
        $origin = $request->getHeaderLine('Origin');

        if (in_array('*', $allowOrigins, true)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        } elseif ($origin !== '' && in_array($origin, $allowOrigins, true)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Vary', 'Origin');
        } else {
            // 不在白名单内的来源不加 CORS 头，浏览器会自行拦截
            return $response;
        }

        $requestHeaders = $request->getHeaderLine('Access-Control-Request-Headers');

        return $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', (array) config('cors.allow_methods', ['GET', 'POST'])))
            ->withHeader('Access-Control-Allow-Headers', $requestHeaders !== '' ? $requestHeaders : implode(', ', (array) config('cors.allow_headers', ['Authorization'])))
            ->withHeader('Access-Control-Expose-Headers', implode(', ', (array) config('cors.expose_headers', ['X-Request-Id'])))
            ->withHeader('Access-Control-Max-Age', (string) config('cors.max_age', 86400));
    }
}
