<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
namespace App\Model;

use Carbon\Carbon;

/**
 * 数据字典项。
 *
 * @property int $id
 * @property int $type_id 所属字典类型 ID
 * @property string $label 显示文本
 * @property string $value 存储值
 * @property int $sort 排序
 * @property int $status 1=启用 0=停用
 * @property string $remark 备注
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DictItem extends Model
{
    protected ?string $table = 'dict_items';

    protected array $fillable = ['type_id', 'label', 'value', 'sort', 'status', 'remark'];

    protected array $casts = ['id' => 'integer', 'type_id' => 'integer', 'sort' => 'integer', 'status' => 'integer'];
}
