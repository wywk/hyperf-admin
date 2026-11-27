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

use Hyperf\Contract\ConfigInterface;
use RuntimeException;

/**
 * 存储驱动工厂：按 config/autoload/storage.php 的 driver 构建驱动实例。
 * 新驱动注册到 CREATORS 映射即可，上层业务与 files 表结构不变。
 */
class StorageFactory
{
    /** 驱动名 → 构建器方法 */
    protected const CREATORS = [
        'local' => 'buildLocal',
    ];

    public function __construct(protected ConfigInterface $config)
    {
    }

    public function create(?string $name = null): DriverInterface
    {
        $name = $name ?: (string) $this->config->get('storage.driver', 'local');
        $method = self::CREATORS[$name] ?? null;
        if ($method === null) {
            throw new RuntimeException("未知存储驱动：{$name}");
        }

        return $this->{$method}($name);
    }

    private function buildLocal(string $name): DriverInterface
    {
        return new LocalDriver(
            (string) $this->config->get("storage.{$name}.root", 'storage/uploads'),
            (string) $this->config->get("storage.{$name}.url_prefix", '/uploads'),
        );
    }
}
