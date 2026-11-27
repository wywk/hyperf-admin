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
use App\Process\CrontabProcess;
use Hyperf\AsyncQueue\Process\ConsumerProcess;

/*
 * This file is part of Hyperf.
 *
 * @see     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
return [
    // 异步队列消费进程：从 Redis 队列取任务执行（任务中心 / 异步导出）
    ConsumerProcess::class,

    // 定时任务调度进程：每秒扫描到期任务并执行
    CrontabProcess::class,
];
