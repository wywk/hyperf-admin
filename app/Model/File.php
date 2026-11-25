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
 * 上传文件元数据。
 *
 * @property int $id
 * @property string $name 原始文件名
 * @property string $path 存储相对路径
 * @property string $driver 存储驱动
 * @property string $mime MIME 类型
 * @property string $ext 扩展名
 * @property int $size 字节
 * @property string $sha1 内容摘要
 * @property int $user_id 上传者
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class File extends Model
{
    protected ?string $table = 'files';

    protected array $fillable = ['name', 'path', 'driver', 'mime', 'ext', 'size', 'sha1', 'user_id'];

    protected array $casts = ['id' => 'integer', 'size' => 'integer', 'user_id' => 'integer'];

    /** 图片类扩展名（前端用于判断是否展示缩略图） */
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'];

    public function isImage(): bool
    {
        return in_array($this->ext, self::IMAGE_EXT, true);
    }
}
