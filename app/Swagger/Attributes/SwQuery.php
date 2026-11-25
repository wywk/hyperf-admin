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
 * query 参数。
 *
 * new SwQuery('keyword', desc: '关键字')
 * new SwQuery('status', type: 'integer', enum: [0, 1])
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
class SwQuery extends OA\Parameter
{
    public function __construct(
        string $name,
        string $type = 'string',
        string $desc = '',
        bool $required = false,
        mixed $default = null,
        mixed $example = null,
        array $enum = [],
    ) {
        parent::__construct(
            name: $name,
            in: 'query',
            required: $required,
            description: $desc !== '' ? $desc : null,
            schema: new OA\Schema(
                type: $type,
                default: $default,
                example: $example,
                enum: $enum !== [] ? $enum : null,
            ),
        );
    }
}
