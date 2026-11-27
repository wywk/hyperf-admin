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
 * 定时任务执行器契约：自定义任务实现本接口并注册为可实例化类，
 * crontab_tasks.handler 存类名，调度器按 cron 规则到期后调用 execute()。
 */
interface TaskInterface
{
    /**
     * @param array $params 任务参数（crontab_tasks.params），透传自后台配置
     *
     * @return string 执行结果描述，写入 crontab_logs.output
     */
    public function execute(array $params): string;
}
