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
 * Delete（删除）。
 *
 * #[SwDelete(path: '/api/demo/{id}', summary: '删除示例', tag: '示例管理', params: [new SwId()])]
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class SwDelete extends OA\Delete
{
    public function __construct(
        string $path,
        string $summary,
        string $tag,
        string $desc = '',
        bool $auth = true,
        array $params = [],
        array $errors = [401, 403, 404],
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
            responses: Sw::responses($errors, $responses),
        );
    }
}
