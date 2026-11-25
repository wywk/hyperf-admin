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
 * JSON 请求体：props 是 SwProp 列表；requiredProps 指定必填字段名。
 *
 * new SwBody(
 *     props: [new SwProp('name', desc: '名称'), new SwProp('price', type: 'number', nullable: true)],
 *     requiredProps: ['name'],
 * )
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class SwBody extends OA\RequestBody
{
    public function __construct(
        array $props = [],
        array $requiredProps = [],
        bool $required = true,
        string $desc = '',
    ) {
        parent::__construct(
            required: $required,
            description: $desc !== '' ? $desc : null,
            content: new OA\JsonContent(
                required: $requiredProps !== [] ? $requiredProps : null,
                properties: $props !== [] ? $props : null,
            ),
        );
    }
}
