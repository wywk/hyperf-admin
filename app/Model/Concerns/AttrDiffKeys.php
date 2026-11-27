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

namespace App\Model\Concerns;

/**
 * 字段级变更快照的协程上下文键与采集限额（trait 在 PHP 8.1 不允许定义常量）。
 */
final class AttrDiffKeys
{
    /** Context 中累积变更条目的键 */
    public const CONTEXT_KEY = 'oplog:attr_diffs';

    /** Context 中「采集已开启」标记键（中间件处理写请求时设置） */
    public const OPEN_KEY = 'oplog:attr_diffs_open';

    /** 单请求最多记录的字段变更数，超出记入截断标记 */
    public const MAX_FIELDS = 50;
}
