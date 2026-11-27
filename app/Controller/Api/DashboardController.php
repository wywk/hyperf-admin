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

namespace App\Controller\Api;

use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Model\CrontabTask;
use App\Model\File;
use App\Model\LoginLog;
use App\Model\OperationLog;
use App\Model\Role;
use App\Model\User;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwResp;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 仪表盘统计：仅需登录，返回聚合数字（不含明细）。
 */
class DashboardController extends AbstractController
{
    #[SwGet(
        path: '/api/dashboard/stats',
        operationId: 'dashboardStats',
        summary: '仪表盘统计',
        tag: '仪表盘',
        desc: '系统聚合统计：用户/角色/文件数、今日登录与操作数、定时任务状态、近 7 日操作趋势。仅需登录。',
        responses: [new SwResp(desc: '聚合统计对象', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'users_total', type: 'integer', description: '用户总数'),
                new OA\Property(property: 'users_enabled', type: 'integer', description: '启用用户数'),
                new OA\Property(property: 'roles_total', type: 'integer'),
                new OA\Property(property: 'files_total', type: 'integer'),
                new OA\Property(property: 'logins_today', type: 'integer', description: '今日登录成功次数'),
                new OA\Property(property: 'operations_today', type: 'integer', description: '今日写操作数'),
                new OA\Property(property: 'operations_total', type: 'integer'),
                new OA\Property(property: 'crontab_enabled', type: 'integer'),
                new OA\Property(property: 'crontab_failing', type: 'integer', description: '连续失败中的任务数'),
                new OA\Property(property: 'trend', type: 'array', items: new OA\Items(type: 'object'), description: '近 7 日操作数 [{date,count}]'),
            ]),
        ])]
    )]
    public function stats(): ResponseInterface
    {
        $todayStart = date('Y-m-d 00:00:00');

        $trend = [];
        for ($i = 6; $i >= 0; --$i) {
            $dayStart = date('Y-m-d 00:00:00', strtotime("-{$i} days"));
            $dayEnd = date('Y-m-d 23:59:59', strtotime("-{$i} days"));
            $trend[] = [
                'date' => date('m-d', strtotime("-{$i} days")),
                'count' => (int) OperationLog::query()->whereBetween('created_at', [$dayStart, $dayEnd])->count(),
            ];
        }

        return ApiResponse::success([
            'users_total' => (int) User::query()->count(),
            'users_enabled' => (int) User::query()->where('status', 1)->count(),
            'roles_total' => (int) Role::query()->count(),
            'files_total' => (int) File::query()->count(),
            'logins_today' => (int) LoginLog::query()
                ->where('event', LoginLog::EVENT_LOGIN_SUCCESS)
                ->where('created_at', '>=', $todayStart)->count(),
            'operations_today' => (int) OperationLog::query()
                ->where('created_at', '>=', $todayStart)->count(),
            'operations_total' => (int) OperationLog::query()->count(),
            'crontab_enabled' => (int) CrontabTask::query()->where('status', CrontabTask::STATUS_ENABLED)->count(),
            'crontab_failing' => (int) CrontabTask::query()->where('status', CrontabTask::STATUS_ENABLED)
                ->where('consecutive_failures', '>', 0)->count(),
            'trend' => $trend,
        ]);
    }
}
