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

namespace App\Crontab;

/**
 * 示例任务：模拟一份日报，演示 TaskInterface 用法。
 * crontab_tasks.handler 填 App\Crontab\DemoReportTask 即可。
 */
class DemoReportTask implements TaskInterface
{
    public function execute(array $params): string
    {
        $title = (string) ($params['title'] ?? '日报');

        return sprintf('%s已生成，共 %d 条待办', $title, random_int(1, 99));
    }
}
