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
use Hyperf\AsyncQueue\Driver\RedisDriver;

/*
 * This file is part of hyperf-admin.
 *
 * @see     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

return [
    'default' => [
        'driver' => RedisDriver::class,
        'redis' => 'default',
        'channel' => 'async-queue',
        'timeout' => 120,
        'retry_seconds' => 5,
        'handle_timeout' => 60,
        'processes' => 1,
        'concurrent' => [
            'limit' => 5,
        ],
        'max_attempts' => 3,
    ],
];
