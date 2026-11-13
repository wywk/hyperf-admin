#!/usr/bin/env php
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
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\ClassLoader;
use Hyperf\Engine\DefaultOption;
use Psr\Container\ContainerInterface;

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '1G');

error_reporting(E_ALL);
// 时区在容器就绪后按 config('app_timezone') 设置，见下方匿名函数

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

require BASE_PATH . '/vendor/autoload.php';

! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', DefaultOption::hookFlags());

// Self-called anonymous function that creates its own scope and keep the global namespace clean.
(function () {
    ClassLoader::init();
    /** @var ContainerInterface $container */
    $container = require BASE_PATH . '/config/container.php';

    // 容器就绪后再设置时区，这样才能读到配置里的 app_timezone
    date_default_timezone_set((string) $container->get(ConfigInterface::class)->get('app_timezone', 'Asia/Shanghai'));

    $application = $container->get(ApplicationInterface::class);
    $application->run();
})();
