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
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\ExceptionHandler\ExceptionHandler;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use function Hyperf\Config\config;

/**
 * 兜底处理器：所有未被前面处理器接住的异常都会走到这里。
 * 生产环境不暴露堆栈，开发环境返回异常类名/位置，便于定位。
 */
class AppExceptionHandler extends ExceptionHandler
{
    public function __construct(protected StdoutLoggerInterface $logger)
    {
    }

    public function handle(Throwable $throwable, ResponseInterface $response)
    {
        $this->stopPropagation();

        $this->logger->error(sprintf(
            '[%s] %s in %s:%d',
            get_class($throwable),
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine()
        ));
        $this->logger->error($throwable->getTraceAsString());

        $data = null;
        if ((string) config('app_env', 'dev') !== 'prod') {
            $data = [
                'exception' => get_class($throwable),
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
            ];
        }

        return ApiResponse::build(ApiCode::SERVER_ERROR, null, $data, $response);
    }

    public function isValid(Throwable $throwable): bool
    {
        return true;
    }
}
