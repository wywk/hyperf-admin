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

namespace App\Job;

use App\Model\Job;
use App\Service\JobService;
use Hyperf\Context\ApplicationContext;

/**
 * 异步导出任务：按台账里的 type + payload 生成 xlsx，登记到文件管理后回写 result_file_id。
 * 状态流转 / 失败落库 / 完成通知都在 BaseJob 里。
 */
class ExportJob extends BaseJob
{
    public function run(): void
    {
        /** @var null|Job $record */
        $record = Job::query()->find($this->jobId);
        if ($record === null) {
            return;
        }

        $svc = ApplicationContext::getContainer()->get(JobService::class);
        $fileId = $svc->executeExport($record);

        $record->result_file_id = $fileId;
        $record->save();
    }
}
