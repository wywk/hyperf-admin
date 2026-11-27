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
use Hyperf\Di\ClassLoader;
use PHPUnit\TextUI\Application;
use Swoole\Coroutine;
use Swoole\ExitException;

require __DIR__ . '/../vendor/autoload.php';

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));

if (! extension_loaded('swoole')) {
    // 无 Swoole 环境退回原生 phpunit（只能跑不涉及协程的纯逻辑测试）
    $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../vendor/bin/phpunit')
        . ' ' . implode(' ', array_map('escapeshellarg', array_slice($_SERVER['argv'], 1)));
    passthru($cmd, $code);
    exit($code);
}

// 1. 注解扫描 + 代理类生成在协程外执行（pcntl_fork 正常工作）
ClassLoader::init();
define('HYPERF_SCAN_DONE', true);

// 2. 整个 PHPUnit 进程跑在一个协程里，Hyperf\Testing 的 HTTP 客户端由此可用
$status = null;
Coroutine\run(static function () use (&$status): void {
    try {
        $status = (new Application())->run($_SERVER['argv']);
    } catch (ExitException $e) {
        // PHPUnit 内部多处调用 exit()，在协程中会变成 ExitException
        $status = (int) ($e->getStatus() ?: 1);
    }
});

exit($status ?? 1);
