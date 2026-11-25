<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */

namespace App\Controller\Api;

use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Service\CodeGeneratorService;
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwId;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwResp;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 代码生成器接口。
 *
 * 注意：生成器会把文件写进运行中的项目（后端 + frontend），
 * 只适合开发环境使用；生产镜像里没有 frontend，前端文件会自动跳过。
 */
class GenController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected CodeGeneratorService $generator)
    {
    }

    /**
     * GET /api/gen/tables 可选的业务表列表。
     */
    #[SwGet(path: '/api/gen/tables', operationId: 'genTables', summary: '可选的业务表列表', tag: '代码生成器',
        desc: '读取当前库 information_schema，返回带注释与行数估算的表清单；框架表标记 is_system 不可生成。需要 gen.view 权限。',
        responses: [new SwResp(desc: '表清单', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'ha_mall_goods'),
                        new OA\Property(property: 'comment', type: 'string'),
                        new OA\Property(property: 'approx_rows', type: 'integer'),
                        new OA\Property(property: 'is_system', type: 'boolean'),
                        new OA\Property(property: 'no_prefix', type: 'boolean', description: '不符合统一前缀规范时为 true'),
                    ]
                )),
            ]),
        ])]
    )]
    public function tables(): ResponseInterface
    {
        $this->authorize('gen.view');

        return ApiResponse::success(['items' => $this->generator->tables()]);
    }

    /**
     * GET /api/gen/tables/{table}/fields 表字段信息（供前端配置）。
     */
    #[SwGet(path: '/api/gen/tables/{table}/fields', operationId: 'genFields', summary: '表字段信息', tag: '代码生成器',
        desc: '读取 information_schema.columns，返回字段名/类型/注释/是否必填等，供前端配置控件。需要 gen.view 权限。',
        params: [new SwId('table', type: 'string', desc: '物理表名', example: 'ha_mall_goods')],
        errors: [401, 403, 404],
        responses: [new SwResp(desc: '{items: [{name, type, comment, ...}]}')]
    )]
    public function fields(string $table): ResponseInterface
    {
        $this->authorize('gen.view');

        return ApiResponse::success(['items' => $this->generator->fields($table)]);
    }

    /**
     * POST /api/gen/preview 预览生成代码（不写文件、不注册权限）。
     */
    #[SwPost(path: '/api/gen/preview', operationId: 'genPreview', summary: '预览生成代码', tag: '代码生成器',
        desc: 'dryRun：返回将生成的文件内容映射（文件路径 → 代码文本），不写文件、不注册权限。需要 gen.create 权限。',
        body: new SwBody(
            props: [
                new SwProp('table', desc: '物理表名', example: 'ha_mall_goods'),
                new SwProp('module', desc: '模块标识（路由/权限码/前端文件名）', example: 'mall_goods'),
                new SwProp('controller', desc: '类名前缀，缺省按模块名 StudlyCase', example: 'MallGoods'),
                new OA\Property(property: 'menu', type: 'object',
                    properties: [
                        new OA\Property(property: 'parent_id', type: 'integer', description: '菜单父节点 ID'),
                        new OA\Property(property: 'name', type: 'string', example: '商品管理'),
                        new OA\Property(property: 'path', type: 'string', example: 'mall/goods', description: '前端路由 path（与菜单一致）'),
                        new OA\Property(property: 'icon', type: 'string'),
                        new OA\Property(property: 'sort', type: 'integer'),
                    ]),
                new OA\Property(property: 'fields', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'name', type: 'string', example: 'name'),
                        new OA\Property(property: 'label', type: 'string', example: '商品名称'),
                        new OA\Property(property: 'form_type', type: 'string',
                            enum: ['input', 'textarea', 'number', 'select', 'radio', 'checkbox', 'switch', 'date']),
                        new OA\Property(property: 'required', type: 'boolean'),
                        new OA\Property(property: 'list', type: 'boolean', description: '是否出现在列表'),
                        new OA\Property(property: 'search', type: 'boolean', description: '是否作为搜索条件'),
                        new OA\Property(property: 'form', type: 'boolean', description: '是否出现在表单'),
                        new OA\Property(property: 'options', type: 'array', items: new OA\Items(type: 'string'),
                            description: 'select/radio 选项，支持 "1=上架" 值=标签写法'),
                    ]
                )),
            ],
            required: false,
        ),
        responses: [new SwResp(desc: '{files: {路径: 内容}, permissions: []}')]
    )]
    public function preview(): ResponseInterface
    {
        $this->authorize('gen.create');

        $config = $this->validatedConfig();

        return ApiResponse::success($this->generator->generate($config, dryRun: true));
    }

    /**
     * POST /api/gen/generate 执行生成（写文件 + 注册菜单权限）。
     */
    #[SwPost(path: '/api/gen/generate', operationId: 'genGenerate', summary: '执行代码生成', tag: '代码生成器',
        desc: '写入 Model/Service/Controller 与前端 api/页面，补丁路由文件，注册菜单与 view/add/edit/delete 按钮权限并授予系统角色；幂等可重复执行。仅开发环境使用。需要 gen.create 权限。',
        body: new SwBody(ref: '#/components/schemas/GenConfig'),
        responses: [new SwResp(desc: '{files: [生成文件路径列表], permissions: {权限码: id}}', props: [
            new OA\Property(property: 'data', type: 'object', properties: [
                new OA\Property(property: 'files', type: 'array', items: new OA\Items(type: 'string')),
                new OA\Property(property: 'permissions', type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'integer')),
            ]),
        ])]
    )]
    public function generate(): ResponseInterface
    {
        $this->authorize('gen.create');

        $config = $this->validatedConfig();

        return ApiResponse::success($this->generator->generate($config));
    }

    private function validatedConfig(): array
    {
        $data = $this->validatorFactory->make($this->request->all(), [
            'table' => 'required|string|max:64|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'module' => 'required|string|max:32|regex:/^[a-z][a-z0-9_-]*$/',
            'controller' => 'nullable|string|max:64|regex:/^[A-Z][A-Za-z0-9]*$/',
            'menu' => 'nullable|array',
            'menu.parent_id' => 'nullable|integer|min:0',
            'menu.name' => 'nullable|string|max:64',
            'menu.path' => 'nullable|string|max:64',
            'menu.icon' => 'nullable|string|max:32',
            'menu.sort' => 'nullable|integer|min:0',
            'fields' => 'required|array|min:1',
            'fields.*.name' => 'required|string|max:64|regex:/^[a-zA-Z_][a-zA-Z0-9_]*$/',
            'fields.*.label' => 'required|string|max:32',
            'fields.*.form_type' => 'required|string|in:input,textarea,number,select,radio,checkbox,switch,date',
            'fields.*.required' => 'nullable|boolean',
            'fields.*.list' => 'nullable|boolean',
            'fields.*.search' => 'nullable|boolean',
            'fields.*.form' => 'nullable|boolean',
            'fields.*.options' => 'nullable|array',
            'fields.*.options.*' => 'string|max:64',
        ], [
            'fields.required' => '至少配置一个字段',
            'module.regex' => '模块标识必须是小写字母开头（字母/数字/下划线/中划线）',
            'fields.*.form_type.in' => '控件类型不合法',
        ])->validate();

        $data['fields'] = array_map(static function (array $field): array {
            return [
                'name' => $field['name'],
                'label' => $field['label'],
                'form_type' => $field['form_type'],
                'required' => ! empty($field['required']),
                'list' => ! empty($field['list']),
                'search' => ! empty($field['search']),
                'form' => ! empty($field['form']),
                'options' => array_values($field['options'] ?? []),
            ];
        }, $data['fields']);

        $data['controller'] = (string) ($data['controller'] ?? '');

        return $data;
    }
}
