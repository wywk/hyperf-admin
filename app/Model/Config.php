<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */

namespace App\Model;

class Config extends Model
{
    protected ?string $table = 'configs';

    public const TYPE_STRING = 'string';

    public const TYPE_TEXT = 'text';

    public const TYPE_NUMBER = 'number';

    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_JSON = 'json';

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
