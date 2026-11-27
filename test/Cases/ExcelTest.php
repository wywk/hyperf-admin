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
use App\Service\Excel\ExcelService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\Arrayable;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ExcelTest extends TestCase
{
    private ExcelService $excel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->excel = ApplicationContext::getContainer()->get(ExcelService::class);
    }

    public function testExportParseRoundtrip(): void
    {
        $headers = ['name' => '名称', 'price' => '价格', 'tags' => '标签'];
        $rows = [
            ['name' => '商品A', 'price' => 12.5, 'tags' => ['热卖', '新品']],
            ['name' => null, 'price' => true, 'tags' => ''],
        ];

        $binary = $this->excel->export('test.xlsx', $headers, $rows);

        $this->assertNotEmpty($binary);
        $this->assertStringStartsWith('PK', $binary); // zip 魔数

        $parsed = $this->excel->parse($binary);
        $this->assertCount(2, $parsed);
        $this->assertSame('商品A', $parsed[0]['名称']);
        $this->assertSame('12.5', $parsed[0]['价格']);
        $this->assertSame('热卖,新品', $parsed[0]['标签']);
        // null -> 空串，bool -> 1/0
        $this->assertSame('', $parsed[1]['名称']);
        $this->assertSame('1', $parsed[1]['价格']);
    }

    public function testEmptyHeadersRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->excel->export('empty.xlsx', [], []);
    }

    public function testInvalidFileRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->excel->parse('this is not a zip');
    }

    public function testParseSkipsEmptyRowsAndStopsAtFirstSheet(): void
    {
        $binary = $this->excel->export('test.xlsx', ['a' => 'A列', 'b' => 'B列'], [
            ['a' => '1', 'b' => 'x'],
            ['a' => '', 'b' => ''],
            ['a' => '2', 'b' => 'y'],
        ]);

        $parsed = $this->excel->parse($binary);
        $this->assertCount(2, $parsed);
        $this->assertSame('2', $parsed[1]['A列']);
    }

    public function testExportAcceptsArrayableModelRows(): void
    {
        // 模拟 Eloquent 模型：Arrayable + 自身属性（(array) 强转会拿到属性表而非数据）
        $model = new class implements Arrayable {
            public $incrementing = true;

            public function toArray(): array
            {
                return ['name' => '模型行', 'price' => 7.5];
            }
        };

        $binary = $this->excel->export('test.xlsx', ['name' => '名称', 'price' => '价格'], [$model]);
        $parsed = $this->excel->parse($binary);

        $this->assertCount(1, $parsed);
        $this->assertSame('模型行', $parsed[0]['名称']);
        $this->assertSame('7.5', $parsed[0]['价格']);
    }
}
