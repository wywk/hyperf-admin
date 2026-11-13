<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 *
 * 数据库命令（migrate / db:seed 等）默认由 hyperf/devtool 注册，而它是
 * require-dev 依赖——生产镜像 composer install --no-dev 后会报
 * Command "migrate" is not defined。这里直接向 hyperf/database 的
 * CommandCollector 注册，保证生产环境也能执行迁移与填充。
 */
return class_exists(\Hyperf\Database\Commands\CommandCollector::class)
    ? \Hyperf\Database\Commands\CommandCollector::getAllCommands()
    : [];
