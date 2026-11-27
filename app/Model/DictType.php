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
use Carbon\Carbon;
use Hyperf\Database\Model\Relations\HasMany;

/**
 * 数据字典类型。
 *
 * @property int $id
 * @property string $name 类型名称
 * @property string $code 类型编码（业务取字典的键）
 * @property string $description 描述
 * @property int $sort 排序
 * @property int $status 1=启用 0=停用
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DictType extends Model
{
    use LogsAttributeChanges;

    protected ?string $table = 'dict_types';

    protected array $fillable = ['name', 'code', 'description', 'sort', 'status'];

    protected array $casts = ['id' => 'integer', 'sort' => 'integer', 'status' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(DictItem::class, 'type_id')->orderBy('sort')->orderBy('id');
    }
}
