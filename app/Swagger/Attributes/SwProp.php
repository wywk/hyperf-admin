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
 * 请求体/响应体字段。
 *
 * new SwProp('name', desc: '名称')
 * new SwProp('price', type: 'number', nullable: true)
 * new SwProp('status', type: 'integer', enum: [0, 1])
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class SwProp extends OA\Property
{
    public function __construct(
        string $name,
        string $type = 'string',
        string $desc = '',
        bool $nullable = false,
        array $enum = [],
        mixed $example = null,
        ?OA\Items $items = null,
        string $format = '',
    ) {
        parent::__construct(
            property: $name,
            type: $type,
            description: $desc !== '' ? $desc : null,
            nullable: $nullable,
            enum: $enum !== [] ? $enum : null,
            example: $example,
            items: $items,
            format: $format !== '' ? $format : null,
        );
    }
}
