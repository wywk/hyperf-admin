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

use App\Common\ApiResponse;
use App\Exception\BusinessException;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class BusinessExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /* @var BusinessException $throwable */
        return ApiResponse::build(
            $throwable->getApiCode(),
            $throwable->getMessage(),
            $throwable->getData(),
            $response
        );
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof BusinessException;
    }
}
