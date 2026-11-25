<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
namespace App\Service\Storage;

use RuntimeException;

/**
 * 本地磁盘驱动：按 YYYYMM/rand-uuid.ext 写入配置根目录。
 *
 * 文件名使用随机 UUID 防猜测；公开访问走 routes.php 里的
 * GET /uploads/{path:.*} 静态路由（LocalDriver::guard 校验防目录穿越）。
 */
class LocalDriver implements DriverInterface
{
    public function __construct(
        protected string $root,
        protected string $urlPrefix,
    ) {
    }

    public function put(string $contents, string $ext): string
    {
        $name = bin2hex(random_bytes(6)) . '-' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
        $relative = date('Ym') . '/' . $name;

        $target = $this->absolute($relative);
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('写入文件失败：' . dirname($relative));
        }

        return $relative;
    }

    public function get(string $path): string
    {
        $target = $this->absolute($path);
        if (! is_file($target)) {
            throw new RuntimeException('文件不存在');
        }

        return (string) file_get_contents($target);
    }

    public function delete(string $path): void
    {
        $target = $this->absolute($path);
        if (is_file($target)) {
            @unlink($target);
        }
    }

    public function exists(string $path): bool
    {
        return is_file($this->absolute($path));
    }

    public function urlPrefix(): string
    {
        return $this->urlPrefix;
    }

    /** 相对路径 → 绝对路径，拒绝 .. 与反斜杠穿越（公开路由也用它做守卫） */
    public function absolute(string $relative): string
    {
        $relative = ltrim($relative, '/');
        if (str_contains($relative, '..') || str_contains($relative, '\\')) {
            throw new RuntimeException('非法的文件路径');
        }

        return BASE_PATH . '/' . $this->root . '/' . $relative;
    }
}
