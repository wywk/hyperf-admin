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
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
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
    public function tables(): ResponseInterface
    {
        $this->authorize('gen.view');

        return ApiResponse::success(['items' => $this->generator->tables()]);
    }

    /**
     * GET /api/gen/tables/{table}/fields 表字段信息（供前端配置）。
     */
    public function fields(string $table): ResponseInterface
    {
        $this->authorize('gen.view');

        return ApiResponse::success(['items' => $this->generator->fields($table)]);
    }

    /**
     * POST /api/gen/preview 预览生成代码（不写文件、不注册权限）。
     */
    public function preview(): ResponseInterface
    {
        $this->authorize('gen.create');

        $config = $this->validatedConfig();

        return ApiResponse::success($this->generator->generate($config, dryRun: true));
    }

    /**
     * POST /api/gen/generate 执行生成（写文件 + 注册菜单权限）。
     */
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
