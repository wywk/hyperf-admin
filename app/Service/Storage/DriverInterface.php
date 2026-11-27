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

namespace App\Service\Storage;

/**
 * 存储驱动接口：新增驱动（OSS/COS/S3...）实现本接口并在
 * config/autoload/storage.php 注册驱动名即可。
 */
interface DriverInterface
{
    /**
     * 写入文件内容，返回存储相对路径（如 20261125/3f2a...-uuid.png）。
     *
     * @param string $contents 文件二进制内容
     * @param string $ext 扩展名（小写，不含点）
     * @return string 相对路径
     */
    public function put(string $contents, string $ext): string;

    /** 读取文件内容（不存在时抛 \RuntimeException） */
    public function get(string $path): string;

    /** 删除文件（不存在时静默成功） */
    public function delete(string $path): void;

    /** 文件是否存在 */
    public function exists(string $path): bool;

    /** 公开访问 URL 前缀（不含文件名） */
    public function urlPrefix(): string;
}
