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

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\File;
use App\Service\Storage\StorageFactory;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 文件管理：上传、分页查询、删除。物理存储委托 StorageFactory 选择的驱动。
 */
class FileManagerService
{
    #[Inject]
    protected StorageFactory $storageFactory;

    #[Inject]
    protected ConfigInterface $config;

    /**
     * 上传文件并落库。
     *
     * @param string $contents 文件二进制内容
     * @param string $clientName 原始文件名
     * @param string $clientMime 客户端报告的 MIME
     * @param int $userId 上传者 ID
     */
    public function upload(string $contents, string $clientName, string $clientMime, int $userId): File
    {
        $maxSize = (int) $this->config->get('storage.max_size', 10485760);
        $size = strlen($contents);
        if ($size <= 0) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '上传内容为空');
        }
        if ($size > $maxSize) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, sprintf('文件超过大小限制（最大 %d MB）', (int) ($maxSize / 1048576)));
        }

        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION) ?: '');
        $this->assertExtAllowed($ext);

        // 内容真实 MIME 优先（客户端 MIME 可伪造），探测失败再用客户端值
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, $contents);
        $mime = mime_content_type($tmp) ?: $clientMime;
        unlink($tmp);

        $driver = $this->storageFactory->create();
        $path = $driver->put($contents, $ext);

        $file = new File();
        $file->name = mb_substr($clientName, 0, 255);
        $file->path = $path;
        $file->driver = $this->config->get('storage.driver', 'local');
        $file->mime = $mime;
        $file->ext = $ext;
        $file->size = $size;
        $file->sha1 = sha1($contents);
        $file->user_id = $userId;
        $file->save();

        return $file;
    }

    /**
     * 分页查询，支持按名称模糊、扩展名、上传者过滤。
     */
    public function page(int $page = 1, int $pageSize = 20, string $keyword = '', ?string $ext = null, ?int $userId = null): array
    {
        $query = File::query();

        if ($keyword !== '') {
            $query->where('name', 'like', "%{$keyword}%");
        }
        if ($ext !== null && $ext !== '') {
            $query->where('ext', strtolower($ext));
        }
        if ($userId !== null && $userId > 0) {
            $query->where('user_id', $userId);
        }

        return [
            'total' => (clone $query)->count(),
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $query->orderByDesc('id')->forPage($page, $pageSize)->get()->all(),
        ];
    }

    public function formatItem(File $file): array
    {
        $driver = $this->storageFactory->create($file->driver);

        return [
            'id' => (int) $file->id,
            'name' => (string) $file->name,
            'path' => (string) $file->path,
            'driver' => (string) $file->driver,
            'mime' => (string) $file->mime,
            'ext' => (string) $file->ext,
            'size' => (int) $file->size,
            'size_text' => $this->humanSize((int) $file->size),
            'url' => $driver->urlPrefix() . '/' . $file->path,
            'is_image' => $file->isImage(),
            'user_id' => (int) $file->user_id,
            'created_at' => $file->created_at?->toDateTimeString(),
        ];
    }

    /**
     * 删除：先删物理文件，再删记录；物理文件缺失时也允许删除记录。
     */
    public function delete(int $id): void
    {
        $file = File::query()->find($id);
        if ($file === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '文件不存在或已删除');
        }

        try {
            $this->storageFactory->create($file->driver)->delete((string) $file->path);
        } catch (RuntimeException) {
            // 物理文件已丢失：仅删记录
        }

        $file->delete();
    }

    private function assertExtAllowed(string $ext): void
    {
        $deny = (array) $this->config->get('storage.deny_ext', []);
        if ($ext !== '' && in_array($ext, $deny, true)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, "不允许上传 .{$ext} 类型文件");
        }

        $allow = (array) $this->config->get('storage.allow_ext', []);
        if ($allow !== [] && ! in_array($ext, $allow, true)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '不允许上传该类型文件，允许：' . implode(' / ', $allow));
        }
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1073741824, 2) . ' GB';
    }
}
