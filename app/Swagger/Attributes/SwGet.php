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

namespace App\Swagger\Attributes;

use Attribute;
use OpenApi\Attributes as OA;

/**
 * Get（列表/详情）。page: true 自动带 page/page_size 分页参数。
 *
 * #[SwGet(path: '/api/demo', summary: '示例列表', tag: '示例管理', page: true)]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class SwGet extends OA\Get
{
    public function __construct(
        string $path,
        string $summary,
        string $tag,
        string $desc = '',
        bool $auth = true,
        bool $page = false,
        array $params = [],
        array $errors = [401, 403],
        array $responses = [],
        ?string $operationId = null,
    ) {
        if ($page) {
            array_push(
                $params,
                new OA\Parameter(
                    name: 'page',
                    in: 'query',
                    required: null,
                    description: '页码，默认 1',
                    schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)
                ),
                new OA\Parameter(
                    name: 'page_size',
                    in: 'query',
                    required: null,
                    description: '每页条数，默认 20',
                    schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)
                ),
            );
        }

        parent::__construct(
            path: $path,
            operationId: $operationId,
            description: $desc !== '' ? $desc : null,
            summary: $summary,
            tags: [$tag],
            security: $auth ? [['bearerAuth' => []]] : null,
            parameters: $params !== [] ? $params : null,
            responses: Sw::responses($errors, $responses),
        );
    }
}
