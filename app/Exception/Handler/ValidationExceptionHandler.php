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
use Hyperf\Collection\Arr;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Hyperf\Validation\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ValidationExceptionHandler extends ExceptionHandler
{
    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        /** @var ValidationException $throwable */
        $errors = $throwable->errors();
        $first = (string) Arr::first(Arr::flatten($errors));

        return ApiResponse::build(
            ApiCode::VALIDATION_FAILED,
            $first !== '' ? $first : ApiCode::VALIDATION_FAILED->message(),
            $errors,
            $response
        );
    }

    public function isValid(Throwable $throwable): bool
    {
        return $throwable instanceof ValidationException;
    }
}
