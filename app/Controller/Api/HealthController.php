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
use App\Controller\Concerns\AuthorizesRequests;
use App\Service\HealthService;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwResp;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 系统健康监控：一次返回 MySQL / Redis / 运行时 / 队列积压 / 磁盘 /
 * 定时任务 / 接口耗时 / 最近错误日志的聚合快照。
 */
class HealthController extends AbstractController
{
    use AuthorizesRequests;

    public function __construct(protected HealthService $health)
    {
    }

    #[SwGet(
        path: '/api/health',
        operationId: 'healthOverview',
        summary: '系统健康快照',
        tag: '系统监控',
        desc: '聚合 MySQL、Redis、PHP/Swoole 运行时、队列积压、磁盘、定时任务（含下次执行时间）、近 1 小时接口耗时（p50/p95/最慢路径）与最近错误日志。需要 health.view 权限。前端每 30 秒轮询。',
        responses: [new SwResp(desc: '各维度指标', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'database', type: 'object'),
                new OA\Property(property: 'redis', type: 'object'),
                new OA\Property(property: 'runtime', type: 'object'),
                new OA\Property(property: 'queue', type: 'object'),
                new OA\Property(property: 'disk', type: 'object'),
                new OA\Property(property: 'crontab', type: 'object'),
                new OA\Property(property: 'api_stats', type: 'object'),
                new OA\Property(property: 'recent_errors', type: 'array', items: new OA\Items(type: 'string')),
            ]),
        ])]
    )]
    public function overview(): ResponseInterface
    {
        $this->authorize('health.view');

        return ApiResponse::success($this->health->overview());
    }
}
