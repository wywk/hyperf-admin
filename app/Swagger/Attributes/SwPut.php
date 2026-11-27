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
 * Put（更新）。body: new SwBody(...) 描述 JSON 请求体。
 *
 * #[SwPut(path: '/api/demo/{id}', summary: '更新示例', tag: '示例管理',
 *     params: [new SwId()], body: new SwBody(props: [new SwProp('name')]))]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class SwPut extends OA\Put
{
    public function __construct(
        string $path,
        string $summary,
        string $tag,
        string $desc = '',
        bool $auth = true,
        ?SwBody $body = null,
        array $params = [],
        array $errors = [401, 403, 404, 422],
        array $responses = [],
        ?string $operationId = null,
    ) {
        parent::__construct(
            path: $path,
            operationId: $operationId,
            description: $desc !== '' ? $desc : null,
            summary: $summary,
            tags: [$tag],
            security: $auth ? [['bearerAuth' => []]] : null,
            parameters: $params !== [] ? $params : null,
            requestBody: $body,
            responses: Sw::responses($errors, $responses),
        );
    }
}
