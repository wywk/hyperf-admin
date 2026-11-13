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

namespace App\Exception;

use App\Common\ApiCode;
use RuntimeException;

/**
 * 可预期的业务异常，由 BusinessExceptionHandler 转成统一 JSON 响应。
 */
class BusinessException extends RuntimeException
{
    public function __construct(
        protected readonly ApiCode $apiCode = ApiCode::FAILED,
        ?string $message = null,
        protected readonly mixed $data = null
    ) {
        parent::__construct($message ?? $apiCode->message());
    }

    public function getApiCode(): ApiCode
    {
        return $this->apiCode;
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
