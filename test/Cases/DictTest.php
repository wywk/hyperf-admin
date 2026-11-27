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
use App\Model\DictItem;
use App\Model\DictType;
use App\Service\DictService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class DictTest extends TestCase
{
    private DictService $dict;

    /** @var int[] setUp 创建的类型 ID，tearDown 统一清理 */
    private array $typeIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dict = ApplicationContext::getContainer()->get(DictService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->typeIds as $id) {
            try {
                $this->dict->deleteType($id);
            } catch (Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testCreateTypeAndItems(): void
    {
        $type = $this->createType();
        $item = $this->dict->createItem($type->id, ['label' => '已支付', 'value' => 'paid', 'sort' => 10]);

        $this->assertStringStartsWith('test_order_status', $type->code);
        $this->assertSame('paid', $item->value);

        $items = $this->dict->itemsOf((int) $type->id);
        $this->assertCount(1, $items);
        $this->assertSame('已支付', $items[0]['label']);
    }

    public function testDuplicateCodeRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->dict->createType(['name' => '重复编码', 'code' => 'common_status']);
    }

    public function testInvalidCodeRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->dict->createType(['name' => '非法编码', 'code' => '9bad-code']);
    }

    public function testDuplicateItemValueRejected(): void
    {
        $type = $this->createType();
        $this->dict->createItem($type->id, ['label' => 'A', 'value' => 'a']);

        $this->expectException(BusinessException::class);
        $this->dict->createItem($type->id, ['label' => 'B', 'value' => 'a']);
    }

    public function testItemsReadsOnlyEnabledWithCache(): void
    {
        $type = $this->createType();
        $code = (string) $type->code;
        $this->dict->createItem($type->id, ['label' => '启用项', 'value' => 'on', 'sort' => 10]);
        $this->dict->createItem($type->id, ['label' => '停用项', 'value' => 'off', 'sort' => 20, 'status' => 0]);

        // 停用项不下发
        $items = $this->dict->items($code);
        $this->assertSame([['label' => '启用项', 'value' => 'on']], $items);

        // 写操作后缓存失效：新增项立即可见
        $this->dict->createItem($type->id, ['label' => '第二项', 'value' => 'two', 'sort' => 5]);
        $items = $this->dict->items($code);
        $values = array_map(static fn (array $row) => $row['value'], $items);
        $this->assertContains('two', $values);
        $this->assertNotContains('off', $values);
    }

    public function testDisabledTypeReturnsEmpty(): void
    {
        $type = $this->createType();
        $this->dict->createItem($type->id, ['label' => 'X', 'value' => 'x']);
        $this->dict->updateType((int) $type->id, ['status' => 0]);

        $this->assertSame([], $this->dict->items((string) $type->code));
    }

    public function testDeleteTypeRemovesItems(): void
    {
        $type = $this->createType();
        $item = $this->dict->createItem($type->id, ['label' => 'X', 'value' => 'x']);

        $this->dict->deleteType((int) $type->id);
        $this->typeIds = []; // tearDown 不必重复删

        $this->assertNull(DictType::query()->find($type->id));
        $this->assertNull(DictItem::query()->find($item->id));
        $this->assertSame([], $this->dict->items((string) $type->code));
    }

    public function testItemsMany(): void
    {
        $result = $this->dict->itemsMany(['common_status', 'not_exists_dict']);
        $this->assertArrayHasKey('common_status', $result);
        $this->assertNotEmpty($result['common_status']);
        $this->assertSame([], $result['not_exists_dict']);
    }

    private function createType(): DictType
    {
        $type = $this->dict->createType([
            'name' => '测试订单状态',
            'code' => 'test_order_status_' . uniqid(),
            'description' => 'DictTest',
        ]);
        $this->typeIds[] = (int) $type->id;

        return $type;
    }
}
