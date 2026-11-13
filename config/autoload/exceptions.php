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
use App\Exception\Handler\AppExceptionHandler;
use App\Exception\Handler\BusinessExceptionHandler;
use App\Exception\Handler\HttpExceptionHandler;
use App\Exception\Handler\ValidationExceptionHandler;

/*
 * This file is part of Hyperf.
 *
 * @see     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
return [
    'handler' => [
        'http' => [
            // 顺序即匹配优先级，兜底的 AppExceptionHandler 必须放最后
            HttpExceptionHandler::class,
            ValidationExceptionHandler::class,
            BusinessExceptionHandler::class,
            AppExceptionHandler::class,
        ],
    ],
];
