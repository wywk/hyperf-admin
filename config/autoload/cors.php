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
use function Hyperf\Support\env;

return [
    // 多个来源用英文逗号分隔，* 表示允许全部
    'allow_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOW_ORIGINS', '*'))))),
    'allow_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allow_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Request-Id'],
    'expose_headers' => ['X-Request-Id'],
    // 预检结果缓存时间（秒）
    'max_age' => 86400,
];
