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
 * Delete（删除）。
 *
 * #[SwDelete(path: '/api/demo/{id}', summary: '删除示例', tag: '示例管理', params: [new SwId()])]
 */
#[Attribute(Attribute::TARGET_METHOD)]
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
