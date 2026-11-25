<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\Permission;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

/**
 * 全栈 CRUD 代码生成器。
 *
 * 选一张数据库表 + 配置字段 → 生成后端（Model/Service/Controller/路由块）、
 * 前端（api 模块 + 列表页/表单页、路由块），并自动注册菜单与按钮权限。
 * 路由和前端路由用「标记块」写入，重复生成会覆盖同模块旧代码，不会破坏手写代码。
 */
class CodeGeneratorService
{
    #[Inject]
    protected RbacService $rbacService;

    /** 框架自身的表，不允许生成 CRUD */
    private const SYSTEM_TABLES = [
        'migrations',
        'permissions',
        'roles',
        'role_permissions',
        'users',
        'login_logs',
        'forms',
        'form_submissions',
    ];

    /**
     * 可供选择的业务表（information_schema，当前库）。
     *
     * name 是物理表名（带统一前缀），bare 是去掉前缀后的名字——
     * 生成代码时一律用 bare，前缀由数据库连接自动拼接。
     */
    public function tables(): array
    {
        $prefix = $this->tablePrefix();
        $rows = Db::select(
            'SELECT table_name AS name, table_comment AS comment, table_rows AS approx_rows'
            . ' FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
        );

        return array_values(array_map(function (\stdClass $row) use ($prefix): array {
            $bare = $this->stripPrefix($row->name, $prefix);

            return [
                'name' => $row->name,
                'bare' => $bare,
                'comment' => (string) $row->comment,
                'approx_rows' => (int) $row->approx_rows,
                'is_prefixed' => $bare !== $row->name,
                'is_system' => in_array($bare, self::SYSTEM_TABLES, true),
            ];
        }, $rows));
    }

    /**
     * 表字段信息（列名/类型/注释/可空），供前端配置表单用。
     */
    public function fields(string $table): array
    {
        $this->assertTable($table);

        $rows = Db::select(
            'SELECT column_name AS name, data_type AS data_type, column_comment AS comment,'
            . ' is_nullable AS nullable, column_default AS col_default, column_key AS col_key,'
            . ' character_maximum_length AS max_length'
            . ' FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?'
            . ' ORDER BY ordinal_position',
            [$table]
        );

        return array_values(array_map(static function (\stdClass $row): array {
            return [
                'name' => $row->name,
                'data_type' => $row->data_type,
                'comment' => (string) $row->comment,
                'nullable' => $row->nullable === 'YES',
                'default' => $row->col_default,
                'primary' => $row->col_key === 'PRI',
                'max_length' => $row->max_length !== null ? (int) $row->max_length : null,
            ];
        }, $rows));
    }

    /**
     * 生成代码。$config 结构见 GenController::validateConfig。
     *
     * @return array{files: array<string, string>, permissions: array<string, int>}
     */
    public function generate(array $config, bool $dryRun = false): array
    {
        $module = $config['module'];
        $studly = $config['controller'] !== '' ? $config['controller'] : self::studly($module);
        $table = $config['table'];
        $this->assertTable($table);
        // 模型/类名/路由都用去前缀的表名，物理前缀由数据库连接自动拼接
        $bare = $this->stripPrefix($table, $this->tablePrefix());

        $fields = $config['fields'];
        if ($fields === []) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '至少配置一个字段');
        }

        $columns = $this->fields($table);
        $columnMeta = [];
        foreach ($columns as $meta) {
            $columnMeta[$meta['name']] = $meta;
        }
        foreach ($fields as $field) {
            if (! in_array($field['name'], array_keys($columnMeta), true)) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, "字段 {$field['name']} 不在表 {$table} 里");
            }
        }

        $ctx = $this->buildContext($module, $studly, $bare, $fields, (string) ($config['menu']['name'] ?? '') ?: $studly, (string) ($config['menu']['path'] ?? ''), $columnMeta);

        $files = [
            "app/Model/{$studly}.php" => $this->renderModel($ctx),
            "app/Service/{$studly}Service.php" => $this->renderService($ctx),
            "app/Controller/Api/{$studly}Controller.php" => $this->renderController($ctx),
            'frontend/src/api/gen/' . $module . '.ts' => $this->renderFrontendApi($ctx),
            'frontend/src/views/gen/' . $module . '/index.vue' => $this->renderFrontendPage($ctx),
        ];

        if ($dryRun) {
            return ['files' => $files, 'permissions' => []];
        }

        // 写文件（生产镜像里没有 frontend 目录，前端文件自动跳过）
        $base = $this->basePath();
        foreach ($files as $relative => $content) {
            if (str_starts_with($relative, 'frontend/') && ! is_dir($base . '/frontend/src')) {
                continue;
            }
            $path = $base . '/' . $relative;
            $dir = dirname($path);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, $content);
        }

        $this->patchRoutes($module, $ctx['routes_block']);
        $this->patchFrontendRoutes($module, $ctx['frontend_route']);

        $permissions = $this->registerPermissions($module, $config['menu']);

        return ['files' => array_merge(array_keys($files), [
            'config/routes.php（已更新，需重启后端生效）',
            'frontend/src/router/routes.ts（已更新）',
        ]), 'permissions' => $permissions];
    }

    /** 项目根目录（Hyperf 里没有 Laravel 的 base_path()） */
    private function basePath(string $relative = ''): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);

        return $relative === '' ? $base : $base . '/' . ltrim($relative, '/');
    }

    /* ---------------- 权限注册 ---------------- */

    /**
     * 注册菜单节点 + 查看/新增/编辑/删除按钮。已存在的权限码直接复用（重复生成安全）。
     *
     * @return array<string, int> code => id
     */
    private function registerPermissions(string $module, array $menu): array
    {
        $menuCode = $module;

        $menuNode = Permission::query()->where('code', $menuCode)->first();
        if ($menuNode === null) {
            // 走 RbacService，菜单节点也会自动授予系统角色（否则超管看不到新菜单）
            $created = $this->rbacService->createPermission([
                'parent_id' => (int) ($menu['parent_id'] ?? 0),
                'type' => 'menu',
                'code' => $menuCode,
                'name' => (string) ($menu['name'] ?? $module),
                'path' => (string) ($menu['path'] ?? $module),
                'icon' => (string) ($menu['icon'] ?? 'Document'),
                'visible' => 1,
                'sort' => (int) ($menu['sort'] ?? 50),
                'description' => '由代码生成器创建',
            ]);
            $menuNode = Permission::query()->find((int) $created['id']);
        }

        $ids = [$menuCode => (int) $menuNode->id];

        $buttons = [
            'view' => '查看',
            'add' => '新增',
            'edit' => '编辑',
            'delete' => '删除',
        ];
        foreach ($buttons as $suffix => $verb) {
            $code = $menuCode . '.' . $suffix;
            if (Permission::query()->where('code', $code)->exists()) {
                $ids[$code] = (int) Permission::query()->where('code', $code)->value('id');
                continue;
            }
            $created = $this->rbacService->createPermission([
                'parent_id' => (int) $menuNode->id,
                'type' => 'button',
                'code' => $code,
                'name' => $verb . ($menu['name'] ?? $module),
                'sort' => count($ids) * 10,
            ]);
            $ids[$code] = (int) $created['id'];
        }

        return $ids;
    }

    /* ---------------- 路由补丁 ---------------- */

    private function patchRoutes(string $module, string $block): void
    {
        $file = $this->basePath('config/routes.php');
        $content = file_get_contents($file);
        if ($content === false) {
            throw new BusinessException(ApiCode::FAILED, '读不到 config/routes.php');
        }

        $pattern = '#// \[gen:' . $module . '\] start.*?// \[gen:' . $module . '\] end\n?#s';
        $wrapped = "\n// [gen:" . $module . '] start（代码生成器维护，勿手改）' . "\n" . $block . "\n// [gen:" . $module . '] end' . "\n";

        $content = preg_replace($pattern, '', $content);
        if ($content === null) {
            throw new BusinessException(ApiCode::FAILED, 'config/routes.php 正则处理失败');
        }

        file_put_contents($file, rtrim($content) . "\n" . $wrapped);
    }

    private function patchFrontendRoutes(string $module, string $block): void
    {
        $file = $this->basePath('frontend/src/router/routes.ts');
        if (! is_file($file)) {
            return; // 生产镜像里没有 frontend，跳过
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return;
        }

        $pattern = '#[ \t]*// \[gen:' . $module . '\] start.*?// \[gen:' . $module . '\] end[ \t]*\n?#s';
        $content = preg_replace($pattern, '', $content) ?? $content;

        // 插到 layoutRoutes 数组结尾（紧邻 export const routes）。数组收尾的 ']' 可能带缩进，
        // 用正则匹配，避免残留空格导致锚点找不到、补丁静默失败。
        $anchor = '#\n[ \t]*\](?=\n\nexport const routes)#';
        if (preg_match($anchor, $content) !== 1) {
            return;
        }

        $wrapped = "\n  // [gen:" . $module . '] start（代码生成器维护，勿手改）' . "\n"
            . $block
            . "\n  // [gen:" . $module . '] end';

        // 用回调替换，避免 $ / 反斜杠被当成反向引用
        $content = preg_replace_callback($anchor, static fn (): string => $wrapped . "\n]", $content, 1) ?? $content;
        file_put_contents($file, $content);
    }

    /* ---------------- 上下文 ---------------- */

    /** module → StudlyCase */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    private function buildContext(string $module, string $studly, string $table, array $fields, string $menuName, string $menuPath, array $columnMeta = []): array
    {
        $rules = [];
        $casts = [];
        $searchWheres = [];
        $listColumns = [];
        $formItems = [];
        $searchItems = [];
        $tableColumns = [];
        $searchData = [];
        $formDefaults = [];
        $formRules = [];
        $fillable = [];
        $optionMaps = [];
        $searchParams = [];
        $formFieldSpecs = [];
        $exportColumns = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $label = $field['label'];
            $formType = $field['form_type'];
            $required = ! empty($field['required']);

            // 字符串 max 从列长度推导，避免校验规则比数据库列还宽（导入时 DB 截断报错）
            $maxLength = (int) ($columnMeta[$name]['max_length'] ?? 0);
            $ruleBody = match ($formType) {
                'textarea' => 'string|max:' . ($maxLength > 0 ? min(2000, $maxLength) : 2000),
                'number' => 'numeric',
                'checkbox' => 'array',
                'switch' => 'boolean',
                'date' => 'date',
                'select', 'radio' => $this->optionRule($field),
                default => 'string|max:' . ($maxLength > 0 ? min(191, $maxLength) : 191),
            };
            $rules[] = "            '{$name}' => '" . ($required ? 'required|' : 'nullable|') . $ruleBody . "',";
            if ($name !== 'id') {
                $fillable[] = "        '{$name}',";
                // 导出列：除 id 外全部业务字段（ExcelService 负责把数组/日期等转成文本）
                $exportColumns[] = "        '{$name}' => '{$label}',";
            }

            $casts[] = match ($formType) {
                'number' => "        '{$name}' => 'float',",
                'checkbox' => "        '{$name}' => 'array',",
                'switch' => "        '{$name}' => 'boolean',",
                'date' => "        '{$name}' => 'datetime',",
                default => '',
            };

            $formDefaults[] = match ($formType) {
                'number' => "  {$name}: null,",
                'checkbox' => "  {$name}: [],",
                'switch' => "  {$name}: false,",
                default => "  {$name}: '',",
            };

            if (! empty($field['search'])) {
                // 文本字段模糊搜；select/radio（下拉值）/数字/日期等精确匹配。
                // 注意不能用 empty() 判断：字符串 '0' 也是 empty，会漏掉 status=0 这类过滤
                $hasFilter = "isset(\$filters['{$name}']) && \$filters['{$name}'] !== null && \$filters['{$name}'] !== ''";
                $isText = in_array($formType, ['input', 'textarea'], true)
                    || ($formType === 'select' && ($field['options'] ?? []) === []);
                $searchWheres[] = $isText
                    ? "        if ({$hasFilter}) {\n            \$query->where('{$name}', 'like', '%'.\$filters['{$name}'].'%');\n        }"
                    : "        if ({$hasFilter}) {\n            \$query->where('{$name}', \$filters['{$name}']);\n        }";
                $searchData[] = "        '{$name}' => \$data['{$name}'] ?? null,";
                $searchItems[] = $this->frontendFormItem($formType, $field, true);
                $searchParams[] = [
                    'name' => $name,
                    'label' => $label,
                    'type' => $formType === 'number' ? 'number' : 'string',
                ];
            }

            if (! empty($field['form'])) {
                $formFieldSpecs[] = [
                    'name' => $name,
                    'label' => $label,
                    'type' => match ($formType) {
                        'number' => 'number',
                        'checkbox' => 'array',
                        'switch' => 'boolean',
                        default => 'string',
                    },
                    'required' => $required,
                ];
            }

            if (! empty($field['list']) && $name !== 'id') {
                // 开关/下拉渲染成标签，其他列直接展示文本
                if ($formType === 'switch') {
                    $tableColumns[] = "    <el-table-column prop=\"{$name}\" label=\"{$label}\" width=\"90\">\n"
                    . "      <template #default=\"{ row }\">\n"
                    . "        <el-tag :type=\"row.{$name} ? 'success' : 'info'\">{{ row.{$name} ? '是' : '否' }}</el-tag>\n"
                    . "      </template>\n"
                    . "    </el-table-column>";
                } elseif ($formType === 'select' && ($field['options'] ?? []) !== []) {
                    $pairs = [];
                    foreach ($field['options'] as $opt) {
                        $o = self::splitOption($opt);
                        $pairs[] = "  '{$o['value']}': '{$o['label']}',";
                    }
                    $optionMaps[] = "const {$name}Labels: Record<string, string> = {\n" . implode("\n", $pairs) . "\n}";
                    $tableColumns[] = "    <el-table-column prop=\"{$name}\" label=\"{$label}\" width=\"90\">\n"
                    . "      <template #default=\"{ row }\">\n"
                    . "        <el-tag type=\"info\">{{ {$name}Labels[String(row.{$name})] ?? row.{$name} }}</el-tag>\n"
                    . "      </template>\n"
                    . "    </el-table-column>";
                } else {
                    $tableColumns[] = "    <el-table-column prop=\"{$name}\" label=\"{$label}\" min-width=\"130\" show-overflow-tooltip />";
                }
            }

            if (! empty($field['form'])) {
                $formItems[] = $this->frontendFormItem($formType, $field, false, $required);
                if ($required) {
                    $trigger = in_array($formType, ['select', 'radio', 'checkbox', 'switch', 'date'], true) ? 'change' : 'blur';
                    $formRules[] = "  {$name}: [{ required: true, message: '请填写{$label}', trigger: '{$trigger}' }],";
                }
            }
        }

        $casts = array_values(array_filter($casts));
        $searchItems = array_values(array_filter($searchItems));

        return [
            'module' => $module,
            'studly' => $studly,
            'table' => $table,
            'rules' => implode("\n", $rules),
            'casts' => implode("\n", $casts) ?: '        // 无需特殊 cast',
            'fillable' => implode("\n", $fillable) ?: '        // 无业务字段',
            'search_wheres' => implode("\n", $searchWheres) ?: '        // 未配置搜索字段',
            'search_data' => implode("\n", $searchData) ?: '',
            'search_params' => $searchParams,
            'form_fields' => $formFieldSpecs,
            'table_columns' => implode("\n", $tableColumns) ?: '    <!-- 未配置列表字段 -->',
            'form_items' => implode("\n", $formItems) ?: '        <!-- 未配置表单字段 -->',
            'form_defaults' => "{\n" . implode("\n", $formDefaults) . "\n}",
            'search_form' => $searchItems === [] ? '' : implode("\n", $searchItems),
            'menu_name' => $menuName,
            'form_rules' => $formRules === [] ? '{}' : "{\n" . implode("\n", $formRules) . "\n}",
            'option_maps' => $optionMaps === [] ? '' : implode("\n\n", $optionMaps) . "\n",
            'routes_block' => $this->renderRoutesBlock($module, $studly),
            'export_columns' => implode("\n", $exportColumns) ?: "        // 无导出字段",
            'frontend_route' => $this->renderFrontendRoute($module, $studly, $menuName, $menuPath),
        ];
    }

    /** 生成前端表单/搜索控件（完整闭合的 el-form-item） */
    private function frontendFormItem(string $formType, array $field, bool $search, bool $required = false): string
    {
        $name = $field['name'];
        $label = $field['label'];
        $model = $search ? 'query' : 'form';
        $width = $search ? ' style="width: 180px"' : '';
        $options = $field['options'] ?? [];
        $optLines = implode("\n", array_map(static function (string $opt): string {
            $o = self::splitOption($opt);

            return "              <el-option key=\"{$o['value']}\" label=\"{$o['label']}\" value=\"{$o['value']}\" />";
        }, $options));

        $body = match ($formType) {
            'textarea' => "          <el-input v-model=\"{$model}.{$name}\" type=\"textarea\" :rows=\"3\" />",
            'number' => "          <el-input-number v-model=\"{$model}.{$name}\" style=\"width: 100%\" />",
            'select' => "          <el-select v-model=\"{$model}.{$name}\" clearable filterable{$width}>\n{$optLines}\n          </el-select>",
            'radio' => "          <el-radio-group v-model=\"{$model}.{$name}\">\n"
                . implode("\n", array_map(static function (string $opt) use ($name, $model): string {
                    $o = self::splitOption($opt);

                    return "            <el-radio key=\"{$o['value']}\" value=\"{$o['value']}\">{$o['label']}</el-radio>";
                }, $options))
                . "\n          </el-radio-group>",
            'checkbox' => "          <el-checkbox-group v-model=\"{$model}.{$name}\">\n"
                . implode("\n", array_map(static function (string $opt) use ($name, $model): string {
                    $o = self::splitOption($opt);

                    return "            <el-checkbox key=\"{$o['value']}\" value=\"{$o['value']}\">{$o['label']}</el-checkbox>";
                }, $options))
                . "\n          </el-checkbox-group>",
            'switch' => "          <el-switch v-model=\"{$model}.{$name}\" />",
            'date' => "          <el-date-picker v-model=\"{$model}.{$name}\" type=\"date\" value-format=\"YYYY-MM-DD\"{$width} />",
            default => "          <el-input v-model=\"{$model}.{$name}\" clearable{$width} />",
        };

        return "        <el-form-item label=\"{$label}\" prop=\"{$name}\">\n{$body}\n        </el-form-item>";
    }

    /* ---------------- 模板渲染 ---------------- */

    private function renderRoutesBlock(string $module, string $studly): string
    {
        // 用完全限定类名，免得再改 routes.php 顶部的 use 块。
        // 注意：Hyperf 里 addGroup('/api/x') + Router::get('/') 会变成 '/api/x/'（带尾斜杠），
        // 请求 '/api/x' 匹配不上，所以这里按完整路径注册。
        $c = "\\App\\Controller\\Api\\{$studly}Controller::class";

        return "Router::addGroup('/api', function () {\n"
            . "    Router::get('/{$module}', [{$c}, 'index']);\n"
            . "    Router::get('/{$module}/export', [{$c}, 'export']);\n"
            . "    Router::post('/{$module}/import', [{$c}, 'import']);\n"
            . "    Router::post('/{$module}', [{$c}, 'store']);\n"
            . "    Router::get('/{$module}/{id:\\d+}', [{$c}, 'show']);\n"
            . "    Router::put('/{$module}/{id:\\d+}', [{$c}, 'update']);\n"
            . "    Router::delete('/{$module}/{id:\\d+}', [{$c}, 'destroy']);\n"
            . "}, ['middleware' => [AuthMiddleware::class]]);";
    }

    private function renderFrontendRoute(string $module, string $studly, string $menuName, string $menuPath): string
    {
        // 路由 path 必须与权限表里菜单的 path 一致（侧边栏按它跳转），未配置时回退模块名
        $routePath = $menuPath !== '' ? $menuPath : $module;

        return "  {\n"
            . "    path: '{$routePath}',\n"
            . "    name: 'gen-{$module}',\n"
            . "    component: () => import('@/views/gen/{$module}/index.vue'),\n"
            . "    meta: { title: '{$menuName}', permission: '{$module}' },\n"
            . '  },';
    }

    private function renderModel(array $ctx): string
    {
        return <<<PHP
<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Model;

/**
 * 数据表 {$ctx['table']} 的模型（由代码生成器生成，可按需修改）。
 *
 * @property int \$id
 */
class {$ctx['studly']} extends Model
{
    protected ?string \$table = '{$ctx['table']}';

    protected array \$fillable = [
{$ctx['fillable']}
    ];

    protected array \$casts = [
{$ctx['casts']}
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

PHP;
    }

    private function renderService(array $ctx): string
    {
        return <<<PHP
<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Service;

use App\Common\\ApiCode;
use App\\Exception\\BusinessException;
use App\Model\\{$ctx['studly']};
use Hyperf\Database\\Exception\\QueryException;
use Hyperf\DbConnection\Db;

/**
 * {$ctx['studly']} 业务服务（由代码生成器生成，业务逻辑在这里扩展）。
 */
class {$ctx['studly']}Service
{
    /**
     * 分页列表。
     */
    public function paginate(array \$filters, int \$page, int \$pageSize): array
    {
        \$query = {$ctx['studly']}::query()->orderByDesc('id');

{$ctx['search_wheres']}

        \$total = (clone \$query)->count();

        return [
            'total' => \$total,
            'page' => \$page,
            'page_size' => \$pageSize,
            'items' => \$query->forPage(\$page, \$pageSize)->get(),
        ];
    }

    /**
     * 导出全量数据：与列表共用筛选条件，按 id 升序每批 500 条产出（Generator，内存友好）。
     */
    public function exportRows(array \$filters): \\Generator
    {
        \$page = 1;
        do {
            \$query = {$ctx['studly']}::query()->orderBy('id');

{$ctx['search_wheres']}

            \$rows = \$query->forPage(\$page, 500)->get();
            foreach (\$rows as \$row) {
                yield \$row;
            }
            ++\$page;
        } while (\$rows->count() === 500);
    }

    public function create(array \$data): {$ctx['studly']}
    {
        return {$ctx['studly']}::query()->create(\$data);
    }

    /**
     * Excel 导入批量落库：已在控制器逐行校验通过的行，统一在事务内插入。
     *
     * @param array<int, array<string, mixed>> \$rows 字段 => 值
     * @return int 成功插入的行数
     */
    public function insertRows(array \$rows): int
    {
        \$count = 0;
        try {
            Db::transaction(function () use (\$rows, &\$count): void {
                foreach (\$rows as \$row) {
                    {$ctx['studly']}::query()->create(\$row);
                    ++\$count;
                }
            });
        } catch (QueryException \$e) {
            // 逐行校验覆盖不到的数据库层错误（列超长/非空约束等），转成可读的业务异常
            throw new BusinessException(ApiCode::FAILED, '导入失败：部分数据与表结构不匹配（' . \$e->getMessage() . '）');
        }

        return \$count;
    }

    public function update(int \$id, array \$data): {$ctx['studly']}
    {
        /** @var {$ctx['studly']} \$model */
        \$model = {$ctx['studly']}::query()->findOrFail(\$id);
        \$model->fill(\$data)->save();

        return \$model;
    }

    public function delete(int \$id): void
    {
        {$ctx['studly']}::query()->where('id', \$id)->delete();
    }
}

PHP;
    }

    private function renderController(array $ctx): string
    {
        $searchData = $ctx['search_data'];
        $module = $ctx['module'];
        $tag = $ctx['menu_name'];

        // Sw* 注解片段（每个方法一段；默认值由 App\Swagger\Attributes 提供）
        $searchParamLines = implode("\n", array_map(
            static fn (array $p): string => "        new SwQuery('{$p['name']}', type: '{$p['type']}', desc: '{$p['label']}'),",
            $ctx['search_params']
        ));
        $formPropertyLines = implode("\n", array_map(
            static fn (array $f): string => "            new SwProp('{$f['name']}', type: '{$f['type']}', desc: '{$f['label']}'),",
            $ctx['form_fields']
        ));
        $requiredProps = implode(', ', array_map(
            static fn (array $f): string => "'{$f['name']}'",
            array_values(array_filter($ctx['form_fields'], static fn (array $f): bool => (bool) $f['required']))
        ));
        $requiredPropsLine = $requiredProps !== '' ? "\n            requiredProps: [{$requiredProps}]," : '';

        $swIndex = <<<PHP
    #[SwGet(path: '/api/{$module}', operationId: '{$module}Index', summary: '{$tag}列表', tag: '{$tag}', page: true,
        params: [
{$searchParamLines}
        ],
    )]
PHP;
        $swShow = <<<PHP
    #[SwGet(path: '/api/{$module}/{id}', operationId: '{$module}Show', summary: '{$tag}详情', tag: '{$tag}',
        params: [new SwId()],
    )]
PHP;
        $swStore = <<<PHP
    #[SwPost(path: '/api/{$module}', operationId: '{$module}Store', summary: '新增{$tag}', tag: '{$tag}',
        body: new SwBody(
            props: [
{$formPropertyLines}
            ],{$requiredPropsLine}
        ),
    )]
PHP;
        $swUpdate = <<<PHP
    #[SwPut(path: '/api/{$module}/{id}', operationId: '{$module}Update', summary: '更新{$tag}', tag: '{$tag}',
        desc: '支持部分字段更新（传哪些改哪些）。',
        params: [new SwId()],
        body: new SwBody(
            props: [
{$formPropertyLines}
            ],
            required: false,
        ),
    )]
PHP;
        $swDestroy = <<<PHP
    #[SwDelete(path: '/api/{$module}/{id}', operationId: '{$module}Destroy', summary: '删除{$tag}', tag: '{$tag}',
        params: [new SwId()],
    )]
PHP;
        $swExport = <<<PHP
    #[SwGet(path: '/api/{$module}/export', operationId: '{$module}Export', summary: '导出{$tag} Excel', tag: '{$tag}',
        desc: '与列表共用搜索参数，导出全部匹配记录为 xlsx（按 id 升序分批读取）。',
        params: [
{$searchParamLines}
        ],
    )]
PHP;
        $swImport = <<<PHP
    #[SwPost(path: '/api/{$module}/import', operationId: '{$module}Import', summary: '导入{$tag} Excel', tag: '{$tag}',
        desc: '上传 xlsx（multipart，字段 file；表头与导出列一致），逐行校验后批量入库，返回成功数与失败行明细。',
        body: new SwBody(
            props: [
                new SwProp('file', type: 'string', format: 'binary', desc: 'xlsx 文件'),
            ],
            requiredProps: ['file'],
        ),
    )]
PHP;

        return <<<PHP
<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Controller\Api;

use App\\Common\\ApiCode;
use App\\Common\\ApiResponse;
use App\\Controller\\AbstractController;
use App\\Controller\\Concerns\\AuthorizesRequests;
use App\\Model\\{$ctx['studly']};
use App\\Service\\{$ctx['studly']}Service;
use App\\Service\\Excel\\ExcelService;
use App\\Swagger\\Attributes\\SwBody;
use App\\Swagger\\Attributes\\SwDelete;
use App\\Swagger\\Attributes\\SwGet;
use App\\Swagger\\Attributes\\SwId;
use App\\Swagger\\Attributes\\SwPost;
use App\\Swagger\\Attributes\\SwProp;
use App\\Swagger\\Attributes\\SwPut;
use App\\Swagger\\Attributes\\SwQuery;
use Hyperf\\Di\\Annotation\\Inject;
use Hyperf\\Validation\\Contract\\ValidatorFactoryInterface;
use Psr\\Http\\Message\\ResponseInterface;

/**
 * {$ctx['studly']} CRUD 接口（由代码生成器生成）。
 *
 * 路由：/api/{$ctx['module']}（config/routes.php 里的 [gen:{$ctx['module']}] 标记块）。
 * 权限码：{$ctx['module']}.view/.add/.edit/.delete（生成器已注册到权限树）。
 */
class {$ctx['studly']}Controller extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface \$validatorFactory;

    #[Inject]
    protected ExcelService \$excel;

    public function __construct(protected {$ctx['studly']}Service \$service)
    {
    }

{$swIndex}
    public function index(): ResponseInterface
    {
        \$this->authorize('{$ctx['module']}.view');

        \$data = \$this->validatorFactory->make(\$this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
        ] + \$this->searchRules())->validate();

        \$page = max(1, (int) (\$data['page'] ?? 1));
        \$pageSize = min(100, max(1, (int) (\$data['page_size'] ?? 20)));

        return ApiResponse::success(\$this->service->paginate(
            array_filter(\$this->searchFilters(\$data), static fn (\$v) => \$v !== null && \$v !== ''),
            \$page,
            \$pageSize
        ));
    }

{$swShow}
    public function show(int \$id): ResponseInterface
    {
        \$this->authorize('{$ctx['module']}.view');

        \$model = {$ctx['studly']}::query()->find(\$id);
        if (\$model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success(\$model);
    }

{$swStore}
    public function store(): ResponseInterface
    {
        \$this->authorize('{$ctx['module']}.add');

        \$data = \$this->validatorFactory->make(\$this->request->all(), \$this->rules())->validate();

        return ApiResponse::success(['id' => (int) \$this->service->create(\$data)->id], '创建成功');
    }

{$swUpdate}
    public function update(int \$id): ResponseInterface
    {
        \$this->authorize('{$ctx['module']}.edit');

        \$data = \$this->validatorFactory->make(\$this->request->all(), \$this->rulesForUpdate())->validate();
        \$this->service->update(\$id, \$data);

        return ApiResponse::success(['id' => \$id], '保存成功');
    }

{$swDestroy}
    public function destroy(int \$id): ResponseInterface
    {
        \$this->authorize('{$ctx['module']}.delete');

        \$this->service->delete(\$id);

        return ApiResponse::success(null, '已删除');
    }

{$swExport}
    public function export(): ResponseInterface
    {
        \$this->authorize('{$ctx['module']}.view');

        \$data = \$this->validatorFactory->make(\$this->request->all(), \$this->searchRules())->validate();
        \$filters = array_filter(\$this->searchFilters(\$data), static fn (\$v) => \$v !== null && \$v !== '');

        \$binary = \$this->excel->export(
            '{$ctx['module']}.xlsx',
            \$this->exportColumns(),
            \$this->service->exportRows(\$filters)
        );

        return \$this->response->raw(\$binary)
            ->withHeader('Content-Type', ExcelService::XLSX_MIME)
            ->withHeader('Content-Disposition', 'attachment; filename="{$ctx['module']}.xlsx"');
    }

{$swImport}
    public function import(): ResponseInterface
    {
        \$this->authorize('{$ctx['module']}.add');

        \$uploaded = \$this->request->getUploadedFiles()['file'] ?? null;
        if (\$uploaded === null) {
            return ApiResponse::fail(ApiCode::VALIDATION_FAILED, '缺少上传字段 file（multipart/form-data）');
        }
        if (! str_ends_with(strtolower((string) \$uploaded->getClientFilename()), '.xlsx')) {
            return ApiResponse::fail(ApiCode::VALIDATION_FAILED, '仅支持 .xlsx 文件');
        }

        // 表头文本 => 字段名（与导出列一致：导出的表格填上数据即可直接回导）
        \$headerMap = array_flip(\$this->exportColumns());

        \$rows = \$this->excel->parse((string) \$uploaded->getStream()->getContents());
        \$failed = [];
        \$valid = [];
        foreach (\$rows as \$index => \$parsed) {
            \$row = [];
            foreach (\$parsed as \$header => \$value) {
                \$field = \$headerMap[\$header] ?? null;
                if (\$field !== null) {
                    \$row[\$field] = \$value;
                }
            }

            \$validator = \$this->validatorFactory->make(\$row, \$this->rules());
            if (\$validator->fails()) {
                \$failed[] = ['row' => \$index + 2, 'errors' => \$validator->errors()->all()];
                continue;
            }
            \$valid[] = \$validator->validated();
        }

        \$success = \$valid === [] ? 0 : \$this->service->insertRows(\$valid);

        return ApiResponse::success([
            'total' => count(\$rows),
            'success' => \$success,
            'failed' => \$failed,
        ], \$failed === [] ? '导入完成' : '导入完成（部分行校验失败）');
    }

    private function rules(): array
    {
        return [
{$ctx['rules']}
        ];
    }

    /** 更新支持部分字段提交：required 放宽为 nullable，传了才校验 */
    private function rulesForUpdate(): array
    {
        return array_map(
            static fn (string \$rule): string => str_replace('required', 'nullable', \$rule),
            \$this->rules()
        );
    }

    /** 搜索字段的校验规则（生成器按配置展开；这里全部放宽为 nullable） */
    private function searchRules(): array
    {
        \$rules = [];
        foreach (\$this->searchFieldNames() as \$name) {
            \$rules[\$name] = 'nullable';
        }

        return \$rules;
    }

    private function searchFieldNames(): array
    {
        return array_keys(\$this->searchFilters([]));
    }

    /** 导出列：字段 => 表头文本（配置的所有业务字段） */
    private function exportColumns(): array
    {
        return [
{$ctx['export_columns']}
        ];
    }

    /** 从请求里提取搜索条件 */
    private function searchFilters(array \$data): array
    {
        return [
{$searchData}
        ];
    }
}

PHP;
    }

    private function renderFrontendApi(array $ctx): string
    {
        return <<<TS
import { downloadFile, http } from '@/api/http'

export interface {$ctx['studly']}Item {
  id: number
  [key: string]: unknown
}

export function fetch{$ctx['studly']}List(params?: Record<string, unknown>) {
  return http.get<{ total: number; page: number; page_size: number; items: {$ctx['studly']}Item[] }>('/{$ctx['module']}', { params })
}

export function fetch{$ctx['studly']}(id: number) {
  return http.get<{$ctx['studly']}Item>(`/{$ctx['module']}/\${id}`)
}

export function create{$ctx['studly']}(data: Partial<{$ctx['studly']}Item>) {
  return http.post<{ id: number }>('/{$ctx['module']}', data)
}

export function update{$ctx['studly']}(id: number, data: Partial<{$ctx['studly']}Item>) {
  return http.put<{ id: number }>(`/{$ctx['module']}/\${id}`, data)
}

export function delete{$ctx['studly']}(id: number) {
  return http.delete<null>(`/{$ctx['module']}/\${id}`)
}

/** 导出 Excel（xlsx Blob），与列表共用搜索参数 */
export function export{$ctx['studly']}Excel(params?: Record<string, unknown>): Promise<Blob> {
  return downloadFile('/{$ctx['module']}/export', params)
}

/** 导入 Excel：返回总数 / 成功数 / 失败行明细 */
export interface {$ctx['studly']}ImportResult {
  total: number
  success: number
  failed: Array<{ row: number; errors: string[] }>
}

export function import{$ctx['studly']}Excel(file: File): Promise<{$ctx['studly']}ImportResult> {
  const form = new FormData()
  form.append('file', file, file.name)
  return http.post<{$ctx['studly']}ImportResult>('/{$ctx['module']}/import', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

TS;
    }

    private function renderFrontendPage(array $ctx): string
    {
        $module = $ctx['module'];
        $studly = $ctx['studly'];

        $template = <<<VUE
<script setup lang="ts">
/**
 * {$studly} 管理页（由代码生成器生成，可按需调整）。
 * 列表 / 搜索 / 新增 / 编辑 / 删除，权限码 {$module}.view/.add/.edit/.delete。
 */
import { onMounted, reactive, ref } from 'vue'
import {
  create{$studly},
  delete{$studly},
  export{$studly}Excel,
  fetch{$studly}List,
  import{$studly}Excel,
  update{$studly},
} from '@/api/gen/{$module}'
import { ElMessage, ElMessageBox } from '@/plugins/element'

const loading = ref(false)
const exporting = ref(false)
{$ctx['option_maps']}
const items = ref<any[]>([])
const total = ref(0)
const query = reactive<Record<string, any>>({
  page: 1,
  page_size: 10,
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetch{$studly}List({ ...query })
    items.value = result.items
    total.value = result.total
  } catch {
    // 错误由请求层统一提示
  } finally {
    loading.value = false
  }
}

function resetQuery(): void {
  for (const key of Object.keys(query)) {
    if (key !== 'page' && key !== 'page_size') {
      delete query[key]
    }
  }
  query.page = 1
  void load()
}

/** 按当前搜索条件导出全量 Excel */
async function exportExcel(): Promise<void> {
  exporting.value = true
  try {
    const filters: Record<string, unknown> = {}
    for (const key of Object.keys(query)) {
      if (key !== 'page' && key !== 'page_size') {
        filters[key] = query[key]
      }
    }
    const blob = await export{$studly}Excel(filters)
    downloadBlob(blob, '{$module}.xlsx')
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string): void {
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename
  link.click()
  URL.revokeObjectURL(link.href)
}

onMounted(load)

/* ---------------- 表单 ---------------- */

const dialogVisible = ref(false)
const submitting = ref(false)
const editingId = ref(0)
const formRef = ref()
const form = reactive<Record<string, any>>({})
const rules: Record<string, unknown> = {$ctx['form_rules']}
const emptyForm: Record<string, any> = {$ctx['form_defaults']}

function openCreate(): void {
  editingId.value = 0
  resetForm()
  Object.assign(form, JSON.parse(JSON.stringify(emptyForm)))
  dialogVisible.value = true
}

function openEdit(row: any): void {
  editingId.value = row.id
  resetForm()
  Object.assign(form, JSON.parse(JSON.stringify(emptyForm)))
  Object.assign(form, JSON.parse(JSON.stringify(row)))
  delete form.id
  dialogVisible.value = true
}

function resetForm(): void {
  for (const key of Object.keys(form)) {
    delete form[key]
  }
}

async function submit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  submitting.value = true
  try {
    if (editingId.value > 0) {
      await update{$studly}(editingId.value, { ...form })
      ElMessage.success('已保存')
    } else {
      await create{$studly}({ ...form })
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    await load()
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    submitting.value = false
  }
}

async function remove(row: any): Promise<void> {
  try {
    await ElMessageBox.confirm('确定删除这条记录吗？', '删除', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  try {
    await delete{$studly}(row.id)
    ElMessage.success('已删除')
    await load()
  } catch {
    // 错误提示由请求层统一处理
  }
}

/* ---------------- 导入 Excel ---------------- */

const importing = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const importResult = reactive<{ visible: boolean; success: number; failed: Array<{ row: number; errors: string[] }> }>({
  visible: false,
  success: 0,
  failed: [],
})

function triggerImport(): void {
  fileInput.value?.click()
}

async function onImportFile(e: Event): Promise<void> {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (!file) {
    return
  }

  importing.value = true
  try {
    const result = await import{$studly}Excel(file)
    importResult.success = result.success
    importResult.failed = result.failed
    importResult.visible = true
    await load()
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    importing.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h2 class="page-title">{$ctx['menu_name']}管理</h2>
      <div class="header-actions">
        <el-button :loading="exporting" @click="exportExcel">导出 Excel</el-button>
        <el-button v-permission="'{$module}.add'" :loading="importing" @click="triggerImport">导入 Excel</el-button>
        <input ref="fileInput" type="file" accept=".xlsx" class="hidden-import-input" @change="onImportFile" />
        <el-button v-permission="'{$module}.add'" type="primary" @click="openCreate">新增</el-button>
      </div>
    </div>

    <el-card shadow="never">
{{SEARCH_BLOCK}}
      <el-table v-loading="loading" :data="items" row-key="id">
        <el-table-column prop="id" label="ID" width="80" />
{$ctx['table_columns']}
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'{$module}.edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'{$module}.delete'" link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无数据" :image-size="80" />
        </template>
      </el-table>

      <div class="pagination">
        <el-pagination
          v-model:current-page="query.page"
          :total="total"
          :page-size="query.page_size"
          layout="total, prev, pager, next"
          background
          @current-change="load"
        />
      </div>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="editingId > 0 ? '编辑' : '新增'" width="560px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
{$ctx['form_items']}
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">保存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="importResult.visible" title="导入结果" width="520px">
      <p>
        共处理 {{ importResult.success + importResult.failed.length }} 行：
        <el-tag type="success">成功 {{ importResult.success }} 行</el-tag>
        <el-tag v-if="importResult.failed.length > 0" type="danger" style="margin-left: 8px">
          失败 {{ importResult.failed.length }} 行
        </el-tag>
      </p>
      <el-table v-if="importResult.failed.length > 0" :data="importResult.failed" size="small" max-height="280">
        <el-table-column prop="row" label="行号（含表头）" width="140" />
        <el-table-column label="失败原因">
          <template #default="{ row }">
            {{ row.errors.join('；') }}
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button type="primary" @click="importResult.visible = false">知道了</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}

.page-title {
  margin: 0;
  font-size: 20px;
}

.header-actions {
  display: flex;
  gap: 8px;
}

.hidden-import-input {
  display: none;
}

.filters {
  margin-bottom: 4px;
}

.pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
</style>

VUE;

        $searchBlock = $ctx['search_form'] === ''
            ? ''
            : "      <el-form inline class=\"filters\" @submit.prevent>\n"
                . $ctx['search_form'] . "\n"
                . "        <el-form-item>\n"
                . "          <el-button type=\"primary\" @click=\"query.page = 1; load()\">查询</el-button>\n"
                . "          <el-button @click=\"resetQuery()\">重置</el-button>\n"
                . "        </el-form-item>\n"
                . "      </el-form>\n";

        return str_replace('{{SEARCH_BLOCK}}', $searchBlock, $template);
    }

    /** 选项校验规则：有选项时按值做 in 校验（兼容数字/字符串提交），否则退回字符串 */
    private function optionRule(array $field): string
    {
        $values = [];
        foreach ($field['options'] ?? [] as $opt) {
            $values[] = self::splitOption($opt)['value'];
        }

        return $values === [] ? 'string|max:191' : 'in:' . implode(',', $values);
    }

    /** 解析选项串："1=启用" → 值 1 / 标签 启用；无等号时值与标签相同 */
    public static function splitOption(string $opt): array
    {
        $pos = strpos($opt, '=');
        if ($pos === false) {
            return ['value' => $opt, 'label' => $opt];
        }

        return ['value' => substr($opt, 0, $pos), 'label' => substr($opt, $pos + 1)];
    }

    private function assertTable(string $table): void
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '表名不合法');
        }
        if (in_array($this->stripPrefix($table, $this->tablePrefix()), self::SYSTEM_TABLES, true)) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, "表 {$table} 是框架表，不允许生成");
        }
        $exists = Db::selectOne(
            'SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if ($exists === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, "表 {$table} 不存在");
        }
    }

    /** 当前连接配置的统一表前缀（未配置时为空串） */
    private function tablePrefix(): string
    {
        return (string) Db::connection()->getConfig('prefix');
    }

    /** 去掉统一前缀；不匹配则原样返回 */
    private function stripPrefix(string $table, string $prefix): string
    {
        if ($prefix !== '' && str_starts_with($table, $prefix)) {
            return substr($table, strlen($prefix));
        }

        return $table;
    }
}
