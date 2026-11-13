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

namespace App\Common;

use Hyperf\Context\ApplicationContext;
use Hyperf\Context\Context;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\HttpServer\Contract\ResponseInterface as HyperfResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * 统一响应结构：{"code": 0, "message": "ok", "data": {...}}.
 */
final class ApiResponse
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public static function success(mixed $data = null, string $message = 'ok'): ResponseInterface
    {
        return self::build(ApiCode::SUCCESS, $message, $data);
    }

    public static function fail(ApiCode $code = ApiCode::FAILED, ?string $message = null, mixed $data = null): ResponseInterface
    {
        return self::build($code, $message, $data);
    }

    /**
     * 按业务码构造响应；异常处理器中可传入已有的 response 以保留其头部信息。
     */
    public static function build(ApiCode $code, ?string $message = null, mixed $data = null, ?ResponseInterface $response = null): ResponseInterface
    {
        return self::raw($code->value, $message ?? $code->message(), $code->httpStatus(), $data, $response);
    }

    public static function raw(int $code, string $message, int $status, mixed $data = null, ?ResponseInterface $response = null): ResponseInterface
    {
        $json = json_encode(['code' => $code, 'message' => $message, 'data' => $data], self::JSON_FLAGS);
        if ($json === false) {
            $json = '{"code":500,"message":"响应序列化失败","data":null}';
        }

        $response ??= ApplicationContext::getContainer()->get(HyperfResponse::class);

        $response = $response->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody(new SwooleStream($json));

        $requestId = Context::get('request_id');
        if (is_string($requestId) && $requestId !== '') {
            $response = $response->withHeader('X-Request-Id', $requestId);
        }

        return $response;
    }
}
