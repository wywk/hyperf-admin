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

namespace App\Listener;

use App\Model\Concerns\LogsAttributeChanges;
use Hyperf\Database\Model\Events\Created;
use Hyperf\Database\Model\Events\Deleted;
use Hyperf\Database\Model\Events\Updating;
use Hyperf\Event\Annotation\Listener;
use Hyperf\Event\Contract\ListenerInterface;
use Throwable;

use function Hyperf\Support\class_uses_recursive;

/**
 * 把挂了 LogsAttributeChanges trait 的模型写事件转成字段级变更快照。
 * 只在操作日志中间件开启标记时采集，队列/命令行写入不受影响。
 */
#[Listener]
class ModelAttrDiffListener implements ListenerInterface
{
    public function listen(): array
    {
        return [
            Updating::class,
            Created::class,
            Deleted::class,
        ];
    }

    public function process(object $event): void
    {
        try {
            $model = $event->getModel();
            // instanceof 不支持 trait 名，必须用 class_uses 递归判断
            if (! in_array(LogsAttributeChanges::class, class_uses_recursive($model), true)) {
                return;
            }

            $name = match ($event::class) {
                Updating::class => 'updating',
                Created::class => 'created',
                Deleted::class => 'deleted',
                default => '',
            };

            if ($name !== '') {
                $model->collectAttrDiff($name);
            }
        } catch (Throwable) {
            // 旁路能力，不影响业务
        }
    }
}
