<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
namespace App\Controller;

use App\Service\Storage\StorageFactory;
use App\Service\Storage\DriverInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * 本地上传文件公开访问（GET /uploads/{path}）。
 *
 * 文件名为随机 UUID 不可猜测；路径经 LocalDriver::absolute 校验拒绝 .. 穿越。
 * 换用对象存储驱动后此路由自然失效（URL 指向云存储）。
 */
class UploadServeController extends AbstractController
{
    #[Inject]
    protected StorageFactory $storageFactory;

    public function show(string $path): ResponseInterface
    {
        try {
            /** @var DriverInterface $driver */
            $driver = $this->storageFactory->create('local');
            $contents = $driver->get($path);
        } catch (RuntimeException) {
            return $this->response->withStatus(404);
        }

        $mime = mime_content_type($driver->absolute($path)) ?: 'application/octet-stream';

        // 图片等公开资源：允许浏览器缓存一周
        return $this->response->raw($contents)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=604800');
    }
}
