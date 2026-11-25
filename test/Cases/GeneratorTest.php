<?php

declare(strict_types=1);
/**
 * 代码生成器测试：dryRun 预览产物、表/字段校验。
 *
 * @internal
 * @coversNothing
 */

namespace HyperfTest\Cases;

use App\Exception\BusinessException;
use App\Service\CodeGeneratorService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Schema\Blueprint;
use Hyperf\Database\Schema\Schema;
use Hyperf\DbConnection\Db;
use Hyperf\Testing\TestCase;

class GeneratorTest extends TestCase
{
    private CodeGeneratorService $gen;

    private const TABLE = 'gen_test_items';

    protected function setUp(): void
    {
        parent::setUp();
        $this->gen = ApplicationContext::getContainer()->get(CodeGeneratorService::class);

        // 建临时测试表（连接自动拼 ha_ 前缀）
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('name', 128)->comment('名称');
                $table->unsignedInteger('stock')->default(0)->comment('库存');
                $table->unsignedTinyInteger('status')->default(1)->comment('1=启用 0=停用');
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);
        parent::tearDown();
    }

    public function testDryRunProducesAllFiveFiles(): void
    {
        $result = $this->gen->generate($this->config(), dryRun: true);

        $this->assertSame([], $result['permissions']);

        $keys = array_keys($result['files']);
        $this->assertContains('app/Model/GenTestItems.php', $keys);
        $this->assertContains('app/Service/GenTestItemsService.php', $keys);
        $this->assertContains('app/Controller/Api/GenTestItemsController.php', $keys);
        $this->assertContains('frontend/src/api/gen/gen_test_items.ts', $keys);
        $this->assertContains('frontend/src/views/gen/gen_test_items/index.vue', $keys);

        // dryRun 不落盘
        foreach (array_keys($result['files']) as $file) {
            if (str_starts_with($file, 'app/')) {
                $this->assertFileDoesNotExist(BASE_PATH . '/' . $file);
            }
        }
    }

    public function testGeneratedControllerUsesSwAttributes(): void
    {
        $result = $this->gen->generate($this->config(), dryRun: true);
        $controller = $result['files']['app/Controller/Api/GenTestItemsController.php'];

        $this->assertStringContainsString('use App\\Swagger\\Attributes\\SwGet;', $controller);
        $this->assertStringContainsString('#[SwGet(path: \'/api/gen_test_items\'', $controller);
        $this->assertStringContainsString('summary: \'测试项目列表\'', $controller);
        $this->assertStringContainsString("new SwQuery('status'", $controller);
        $this->assertStringNotContainsString('OpenApi\\Attributes as OA', $controller);
    }

    public function testGeneratedExportPieces(): void
    {
        $result = $this->gen->generate($this->config(), dryRun: true);
        $controller = $result['files']['app/Controller/Api/GenTestItemsController.php'];
        $service = $result['files']['app/Service/GenTestItemsService.php'];
        $api = $result['files']['frontend/src/api/gen/gen_test_items.ts'];
        $page = $result['files']['frontend/src/views/gen/gen_test_items/index.vue'];

        // 控制器：导出端点 + 导出列
        $this->assertStringContainsString("path: '/api/gen_test_items/export'", $controller);
        $this->assertStringContainsString('public function export(): ResponseInterface', $controller);
        $this->assertStringContainsString("'name' => '名称',", $controller);
        $this->assertStringContainsString("'status' => '状态',", $controller);

        // Service：流式导出
        $this->assertStringContainsString('public function exportRows(array $filters): \Generator', $service);
        $this->assertStringContainsString('forPage($page, 500)', $service);

        // 前端：下载 API + 导出按钮
        $this->assertStringContainsString('exportGenTestItemsExcel', $api);
        $this->assertStringContainsString('downloadFile', $api);
        $this->assertStringContainsString('导出 Excel', $page);
    }

    public function testGeneratedModelUsesBareTableName(): void
    {
        $result = $this->gen->generate($this->config(), dryRun: true);
        $model = $result['files']['app/Model/GenTestItems.php'];

        // 模型表名不带前缀，前缀由连接层拼接
        $this->assertStringContainsString("protected ?string \$table = 'gen_test_items';", $model);
    }

    public function testUnknownTableRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->gen->generate($this->config(table: 'no_such_table_xyz'), dryRun: true);
    }

    public function testUnknownFieldRejected(): void
    {
        $config = $this->config();
        $config['fields'][0]['name'] = 'ghost_field';

        $this->expectException(BusinessException::class);
        $this->gen->generate($config, dryRun: true);
    }

    private function config(string $table = self::TABLE): array
    {
        $physical = Db::connection()->getTablePrefix() . $table;

        return [
            'table' => $physical,
            'module' => 'gen_test_items',
            'controller' => '',
            'menu' => [
                'parent_id' => 0,
                'name' => '测试项目',
                'path' => 'gen/test_items',
                'icon' => 'List',
                'sort' => 999,
            ],
            'fields' => [
                [
                    'name' => 'name',
                    'label' => '名称',
                    'form_type' => 'input',
                    'required' => true,
                    'list' => true,
                    'search' => true,
                    'form' => true,
                ],
                [
                    'name' => 'stock',
                    'label' => '库存',
                    'form_type' => 'number',
                    'required' => false,
                    'list' => true,
                    'search' => false,
                    'form' => true,
                ],
                [
                    'name' => 'status',
                    'label' => '状态',
                    'form_type' => 'select',
                    'required' => false,
                    'list' => true,
                    'search' => true,
                    'form' => true,
                    'options' => ['1=启用', '0=停用'],
                ],
            ],
        ];
    }
}
