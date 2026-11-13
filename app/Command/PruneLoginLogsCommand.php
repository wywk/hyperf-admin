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

namespace App\Command;

use App\Model\LoginLog;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;

use function Hyperf\Config\config;

/**
 * 清理过期的登录审计日志，建议放进 crontab 每天跑一次。
 */
#[Command]
class PruneLoginLogsCommand extends HyperfCommand
{
    protected ?string $signature = 'login-log:prune {--days= : 保留最近多少天，默认取 LOGIN_LOG_RETENTION_DAYS}';

    protected string $description = '清理过期的登录审计日志';

    public function handle(): int
    {
        $option = $this->input->getOption('days');
        if ($option !== null && (int) $option < 1) {
            $this->output->error('保留天数必须大于 0');

            return 1;
        }

        $days = (int) ($option ?: config('security.login_log_retention_days', 90));
        if ($days < 1) {
            $this->output->error('LOGIN_LOG_RETENTION_DAYS 必须大于 0');

            return 1;
        }

        $threshold = date('Y-m-d H:i:s', time() - $days * 86400);
        $deleted = LoginLog::query()->where('created_at', '<', $threshold)->delete();

        $this->output->success(sprintf('已清理 %d 条 %s 之前的登录日志', $deleted, $threshold));

        return 0;
    }
}
