<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

return [
    'default' => [
        'driver' => \Hyperf\AsyncQueue\Driver\RedisDriver::class,
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
