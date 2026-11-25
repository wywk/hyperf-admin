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
 * 200 响应体（放进 errors 同级的 responses 数组）。
 *
 * #[SwGet(path: '/api/demo/{id}', summary: '示例详情', tag: '示例管理',
 *     params: [new SwId()],
 *     responses: [new SwResp(desc: '示例详情', props: [new SwProp('id', type: 'integer')])])]
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class SwResp extends OA\Response
{
    public function __construct(
        string $desc = '成功',
        array $props = [],
        mixed $example = null,
        string $code = '200',
    ) {
        $content = null;
        if ($props !== [] || $example !== null) {
            $content = new OA\JsonContent(example: $example, properties: $props !== [] ? $props : null);
        }

        parent::__construct(
            response: $code,
            description: $desc,
            content: $content,
        );
    }
}
