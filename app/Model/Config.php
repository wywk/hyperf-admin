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

namespace App\Model;

use App\Model\Concerns\LogsAttributeChanges;

class Config extends Model
{
    use LogsAttributeChanges;

    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_JSON = 'json';

    protected ?string $table = 'configs';

    protected array $fillable = [
        'group',
        'key',
        'value',
        'type',
        'name',
        'description',
        'sort',
        'is_system',
    ];

    protected array $casts = [
        'id' => 'integer',
        'sort' => 'integer',
        'is_system' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
