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
use App\Service\ConfigService;
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwPut;
use App\Swagger\Attributes\SwResp;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 系统配置：按分组读取、批量保存。
 *
 * 只允许改已存在的键（键由 ConfigSeeder 定义），后端不会因为后台传了个新键就凭空造配置。
 */
class ConfigController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected ConfigService $configService)
    {
    }

    /**
     * GET /api/configs.
     */
    #[SwGet(
        path: '/api/configs',
        operationId: 'configIndex',
        summary: '系统配置（分组）',
        tag: '系统配置',
        desc: '返回按 group 分组的配置项，含 type/name/description，前端据此渲染动态表单。需要 config.view 权限。',
        responses: [new SwResp(desc: '分组配置', props: [
            new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                properties: [
                    new OA\Property(property: 'group', type: 'string', example: 'security'),
                    new OA\Property(property: 'name', type: 'string', example: '安全策略'),
                    new OA\Property(property: 'items', type: 'array', items: new OA\Items(type: 'object')),
                ]
            )),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('config.view');

        return ApiResponse::success($this->configService->grouped());
    }

    /**
     * PUT /api/configs：批量保存.
     */
    #[SwPut(
        path: '/api/configs',
        operationId: 'configUpdate',
        summary: '批量保存配置',
        tag: '系统配置',
        desc: 'body.items 为 {key: value} 映射；值按各自的 type 校验与转换后落库，保存后立即生效（Redis 缓存失效）。需要 config.edit 权限。',
        body: new SwBody(
            props: [
                new SwProp('items', type: 'object', desc: '配置键值对，如 {"login_max_attempts": 8}'),
            ],
            requiredProps: ['items'],
        ),
        responses: [new SwResp(desc: '保存后的全量配置（已按类型转换）')]
    )]
    public function update(): ResponseInterface
    {
        $this->authorize('config.edit');

        $data = $this->validatorFactory->make($this->request->all(), [
            'items' => 'required|array|min:1',
        ], [
            'items.required' => '请提交 items',
            'items.array' => 'items 必须是对象（键值对）',
            'items.min' => '至少提交一项配置',
        ])->validate();

        /** @var array<string, mixed> $items */
        $items = (array) $data['items'];

        return ApiResponse::success($this->configService->setMany($items), '配置已保存');
    }
}
