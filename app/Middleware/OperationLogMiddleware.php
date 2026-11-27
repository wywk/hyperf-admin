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

namespace App\Middleware;

use App\Model\Concerns\AttrDiffKeys;
use App\Service\OperationLogService;
use App\Support\ClientIp;
use Hyperf\Context\Context;
use Hyperf\HttpServer\Router\Dispatched;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * 操作日志中间件：挂在 AuthMiddleware 之后，记录登录用户的写操作
 * （POST/PUT/DELETE）的用户、路径、参数（脱敏）、响应码、耗时与 IP。
 * GET 等读操作不记录。审计为旁路能力，内部异常不阻断业务。
 */
class OperationLogMiddleware implements MiddlewareInterface
{
    private const RECORDED_METHODS = ['POST', 'PUT', 'DELETE', 'PATCH'];

    /**
     * 这些路径不记操作日志：消息中心的「标记已读 / 删除消息」是高频个人操作，
     * 记下来只会把审计日志冲成流水账。
     */
    private const SKIP_PREFIXES = ['/api/notifications'];

    public function __construct(protected OperationLogService $operationLogService)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        if (! in_array($method, self::RECORDED_METHODS, true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $handler->handle($request);
            }
        }

        $start = microtime(true);

        // 开启字段级变更采集：模型事件据此把「改前→改后」快照累积进协程 Context
        Context::set(AttrDiffKeys::OPEN_KEY, true);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $throwable) {
            // 异常也会被记录（响应码 500），再原样抛出让异常处理层接管
            $this->log($request, $method, 500, $start);
            throw $throwable;
        }

        $this->log($request, $method, $response->getStatusCode(), $start);

        return $response;
    }

    private function log(ServerRequestInterface $request, string $method, int $statusCode, float $start): void
    {
        // 防重：测试环境 / 中间件意外重复执行时，同一请求只落一条日志
        if (Context::has('oplog:recorded')) {
            return;
        }

        try {
            $userId = (int) Context::get('user_id');
            /* @var null|\App\Model\User $user */
            $user = Context::get('user');

            $params = array_merge(
                $request->getParsedBody() ?: [],
                $request->getQueryParams()
            );

            // 路由动作：Hyperf 路由分发后把 Dispatched 结果放在 attributes，
            // handler->callback 为 [类, 方法] 数组时拼成 Class@method
            $action = '';
            $dispatched = $request->getAttribute(Dispatched::class);
            if ($dispatched instanceof Dispatched && $dispatched->isFound()) {
                $callback = $dispatched->handler?->callback;
                if (is_array($callback)) {
                    $action = (string) $callback[0] . '@' . (string) $callback[1];
                } elseif (is_string($callback)) {
                    $action = $callback;
                }
            }

            $this->operationLogService->record(
                $userId,
                $user?->username ?? '',
                $method,
                $request->getUri()->getPath(),
                $action,
                $params === [] ? null : $params,
                $statusCode,
                (int) round((microtime(true) - $start) * 1000),
                ClientIp::fromRequest($request),
                mb_substr($request->getHeaderLine('User-Agent'), 0, 512),
                Context::get(AttrDiffKeys::CONTEXT_KEY)
            );
        } catch (Throwable) {
            // 审计失败不影响主流程
        } finally {
            // 标记已记录 + 清理协程上下文，防止长连接复用协程时残留
            Context::set('oplog:recorded', true);
            Context::set(AttrDiffKeys::OPEN_KEY, null);
            Context::set(AttrDiffKeys::CONTEXT_KEY, null);
        }
    }
}
