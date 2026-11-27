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
 * 路径参数（{id}）。
 *
 * new SwId()  —— 默认 name=id、integer
 * new SwId('code', type: 'string', desc: '编码')
 */
#[Attribute(Attribute::TARGET_METHOD)]
class SwId extends OA\Parameter
{
    public function __construct(
        string $name = 'id',
        string $type = 'integer',
        string $desc = 'ID',
        mixed $example = null,
    ) {
        parent::__construct(
            name: $name,
            in: 'path',
            required: true,
            description: $desc !== '' ? $desc : null,
            schema: new OA\Schema(type: $type, example: $example),
        );
    }
}
