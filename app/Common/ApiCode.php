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

/**
 * 业务码：0 表示成功，非 0 表示失败。
 * 失败码与 HTTP 状态码保持一致，便于前端统一处理。
 */
enum ApiCode: int
{
    case SUCCESS = 0;
    case FAILED = 1;
    case UNAUTHORIZED = 401;
    case FORBIDDEN = 403;
    case NOT_FOUND = 404;
    case VALIDATION_FAILED = 422;
    case TOO_MANY_REQUESTS = 429;
    case SERVER_ERROR = 500;

    public function message(): string
    {
        return match ($this) {
            self::SUCCESS => 'ok',
            self::FAILED => '操作失败',
            self::UNAUTHORIZED => '未登录或登录已失效',
            self::FORBIDDEN => '没有权限执行该操作',
            self::NOT_FOUND => '接口不存在',
            self::VALIDATION_FAILED => '参数校验失败',
            self::TOO_MANY_REQUESTS => '请求过于频繁，请稍后再试',
            self::SERVER_ERROR => '服务器内部错误',
        };
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::SUCCESS => 200,
            self::FAILED => 400,
            default => $this->value,
        };
    }
}
