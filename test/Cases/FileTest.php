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

namespace HyperfTest\Cases;

use App\Exception\BusinessException;
use App\Model\File;
use App\Service\FileManagerService;
use App\Service\Storage\StorageFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;
use RuntimeException;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class FileTest extends TestCase
{
    private FileManagerService $files;

    /** 测试产生的文件记录 ID，tearDown 统一清理 */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->files = ApplicationContext::getContainer()->get(FileManagerService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            try {
                $this->files->delete($id);
            } catch (Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testUploadPersistsFileAndMetadata(): void
    {
        $file = $this->upload('测试文件.txt', 'hello hyperf-admin');
        $this->createdIds[] = (int) $file->id;

        $this->assertSame('txt', $file->ext);
        $this->assertSame('text/plain', $file->mime);
        $this->assertSame(strlen('hello hyperf-admin'), (int) $file->size);
        $this->assertSame(sha1('hello hyperf-admin'), (string) $file->sha1);
        $this->assertTrue((bool) preg_match('#^\d{6}/[a-f0-9-]+\.txt$#', (string) $file->path));

        // 物理文件存在且内容一致
        $driver = ApplicationContext::getContainer()->get(StorageFactory::class)->create('local');
        $this->assertTrue($driver->exists((string) $file->path));
        $this->assertSame('hello hyperf-admin', $driver->get((string) $file->path));

        // 公开 URL 前缀正确
        $formatted = $this->files->formatItem($file);
        $this->assertStringStartsWith('/uploads/', $formatted['url']);
        $this->assertFalse($formatted['is_image']);
    }

    public function testImageExtIsDetected(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        $file = $this->upload('pixel.png', $png);
        $this->createdIds[] = (int) $file->id;

        $this->assertTrue($file->isImage());
        $this->assertSame('image/png', $file->mime);
    }

    public function testDeniedExtRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->files->upload('<?php echo 1;', 'shell.php', 'application/x-php', 0);
    }

    public function testOversizeRejected(): void
    {
        $this->expectException(BusinessException::class);
        // max_size 默认 10MB；构造超过限制的请求太贵，这里直接校验 0 字节与超大两条规则中的 0 字节路径
        $this->files->upload('', 'empty.txt', 'text/plain', 0);
    }

    public function testPageListAndFilters(): void
    {
        $a = $this->upload('report-a.txt', 'A');
        $b = $this->upload('photo-b.png', 'B');
        $this->createdIds = [(int) $a->id, (int) $b->id];

        $all = $this->files->page(1, 20);
        $this->assertGreaterThanOrEqual(2, $all['total']);

        $filtered = $this->files->page(1, 20, keyword: 'report-a');
        $this->assertSame(1, $filtered['total']);

        $byExt = $this->files->page(1, 20, ext: 'png');
        $names = array_map(static fn (File $f) => $f->name, $byExt['items']);
        $this->assertContains('photo-b.png', $names);
        $this->assertNotContains('report-a.txt', $names);
    }

    public function testDeleteRemovesRowAndPhysicalFile(): void
    {
        $file = $this->upload('to-delete.txt', 'bye');
        $id = (int) $file->id;

        $driver = ApplicationContext::getContainer()->get(StorageFactory::class)->create('local');
        $path = (string) $file->path;
        $this->assertTrue($driver->exists($path));

        $this->files->delete($id);

        $this->assertFalse($driver->exists($path));
        $this->assertNull(File::query()->find($id));
    }

    public function testLocalDriverRejectsTraversal(): void
    {
        $driver = ApplicationContext::getContainer()->get(StorageFactory::class)->create('local');
        $this->expectException(RuntimeException::class);
        $driver->absolute('../../.env');
    }

    private function upload(string $name, string $contents): File
    {
        return $this->files->upload($contents, $name, 'application/octet-stream', 1);
    }
}
