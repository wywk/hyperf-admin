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

namespace App\Exception\Handler;

use App\Common\ApiCode;
use App\Common\ApiResponse;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\HttpMessage\Exception\HttpException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * 把框架抛出的 HTTP 异常（404 / 405 / 401 等）也转成统一 JSON 结构。
 */
class HttpExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var HttpException $throwable */
        $status = $throwable->getStatusCode();

        [$code, $message] = match ($status) {
            401 => [ApiCode::UNAUTHORIZED, ApiCode::UNAUTHORIZED->message()],
            403 => [ApiCode::FORBIDDEN, ApiCode::FORBIDDEN->message()],
            404 => [ApiCode::NOT_FOUND, ApiCode::NOT_FOUND->message()],
            429 => [ApiCode::TOO_MANY_REQUESTS, ApiCode::TOO_MANY_REQUESTS->message()],
            default => [ApiCode::FAILED, $throwable->getMessage()],
        };

        return ApiResponse::raw($code->value, $message, $status, null, $response);
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof HttpException;
    }
}
