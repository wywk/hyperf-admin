<?php

declare(strict_types=1);
/**
 * PHPUnit 运行器：先在协程外完成 Hyperf 注解扫描，再把整个测试进程包进一个 Swoole 协程。
 *
 * 背景：
 * 1. PHPUnit 10.5 新补丁把 TestCase::runTest() 改为 private，子类（含 Trait）无法覆盖，
 *    Hyperf\Testing 的 RunTestsInCoroutine Trait 随之失效，HTTP 测试客户端会报
 *    "Swoole\Error: API must be called in the coroutine"。
 * 2. Hyperf 的注解扫描依赖 pcntl_fork（PcntlScanHandler），在协程内 fork 不可用，
 *    会导致 Swoole\ExitException。
 *
 * 因此本运行器：协程外扫描 → 协程内跑 phpunit，并把协程内的 exit() 转换为真实退出码。
 *
 * 用法（与 phpunit 参数一致）：
 *   php test/run.php                    # 全量
 *   php test/run.php --filter AuthTest  # 单个用例
 */

use Hyperf\Di\ClassLoader;
use Swoole\Coroutine;

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
        $status = (new PHPUnit\TextUI\Application())->run($_SERVER['argv']);
    } catch (Swoole\ExitException $e) {
        // PHPUnit 内部多处调用 exit()，在协程中会变成 ExitException
        $status = (int) ($e->getStatus() ?: 1);
    }
});

exit($status ?? 1);
