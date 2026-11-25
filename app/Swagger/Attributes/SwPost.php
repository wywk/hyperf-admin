<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Swagger\Attributes;

use OpenApi\Attributes as OA;

/**
 * Post（新增）。body: new SwBody(...) 描述 JSON 请求体。
 *
 * #[SwPost(path: '/api/demo', summary: '新增示例', tag: '示例管理',
 *     body: new SwBody(props: [new SwProp('name')], requiredProps: ['name']))]
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class SwPost extends OA\Post
{
    public function __construct(
        string $path,
        string $summary,
        string $tag,
        string $desc = '',
        bool $auth = true,
        ?SwBody $body = null,
        array $params = [],
        array $errors = [401, 403, 422],
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
