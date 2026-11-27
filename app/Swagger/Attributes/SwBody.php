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
 * JSON 请求体：props 是 SwProp 列表；requiredProps 指定必填字段名。
 *
 * new SwBody(
 *     props: [new SwProp('name', desc: '名称'), new SwProp('price', type: 'number', nullable: true)],
 *     requiredProps: ['name'],
 * )
 */
#[Attribute(Attribute::TARGET_METHOD)]
class SwBody extends OA\RequestBody
{
    public function __construct(
        array $props = [],
        array $requiredProps = [],
        bool $required = true,
        string $desc = '',
        mixed $example = null,
        ?string $ref = null,
    ) {
        parent::__construct(
            required: $required,
            description: $desc !== '' ? $desc : null,
            content: new OA\JsonContent(
                required: $requiredProps !== [] ? $requiredProps : null,
                properties: $props !== [] ? $props : null,
                example: $example,
                ref: $ref,
            ),
        );
    }
}
