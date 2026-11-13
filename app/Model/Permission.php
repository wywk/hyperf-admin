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

class Permission extends Model
{
    public const TYPE_MENU = 'menu';

    public const TYPE_BUTTON = 'button';

    protected ?string $table = 'permissions';

    protected array $fillable = [
        'parent_id',
        'type',
        'code',
        'name',
        'path',
        'icon',
        'visible',
        'is_system',
        'sort',
        'description',
    ];

    protected array $casts = [
        'id' => 'integer',
        'parent_id' => 'integer',
        'visible' => 'integer',
        'is_system' => 'integer',
        'sort' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function isMenu(): bool
    {
        return (string) $this->type === self::TYPE_MENU;
    }

    public function isButton(): bool
    {
        return (string) $this->type === self::TYPE_BUTTON;
    }

    public function isSystem(): bool
    {
        return (int) $this->is_system === 1;
    }
}
