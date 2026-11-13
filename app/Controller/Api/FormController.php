<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */

namespace App\Controller\Api;

use App\Common\ApiCode;
use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Exception\BusinessException;
use App\Model\Form;
use App\Model\FormSubmission;
use App\Support\ClientIp;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * 表单设计器：管理接口 + 公开的表单获取/提交接口。
 */
class FormController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected ServerRequestInterface $serverRequest)
    {
    }

    /**
     * GET /api/forms 表单列表。
     */
    public function index(): ResponseInterface
    {
        $this->authorize('form.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
            'keyword' => 'nullable|string|max:64',
            'status' => 'nullable|integer|in:0,1',
        ])->validate();

        $query = Form::query()->orderByDesc('id');
        if (! empty($data['keyword'])) {
            $keyword = (string) $data['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%");
            });
        }
        if (isset($data['status'])) {
            $query->where('status', (int) $data['status']);
        }

        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($data['page_size'] ?? 20)));
        $total = (clone $query)->count();

        return ApiResponse::success([
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $query->forPage($page, $pageSize)->get()
                ->each(static fn (Form $form) => $form->makeHidden('schema')),
        ]);
    }

    /**
     * GET /api/forms/{id} 表单详情（含 Schema）。
     */
    public function show(int $id): ResponseInterface
    {
        $this->authorize('form.view');

        $form = Form::query()->find($id);
        if ($form === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '表单不存在');
        }

        return ApiResponse::success($form);
    }

    /**
     * POST /api/forms 新增表单。
     */
    public function store(): ResponseInterface
    {
        $this->authorize('form.add');

        $data = $this->validatedSchema();

        $form = new Form();
        $form->name = $data['name'];
        $form->code = $data['code'];
        $form->schema = $data['schema'];
        $form->description = $data['description'];
        $form->status = 1;
        $form->save();

        return ApiResponse::success(['id' => (int) $form->id], '创建成功');
    }

    /**
     * PUT /api/forms/{id} 更新表单。
     */
    public function update(int $id): ResponseInterface
    {
        $this->authorize('form.edit');

        $form = Form::query()->find($id);
        if ($form === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '表单不存在');
        }

        $data = $this->validatedSchema($id);
        $form->name = $data['name'];
        $form->code = $data['code'];
        $form->schema = $data['schema'];
        $form->description = $data['description'];
        if (isset($data['status'])) {
            $form->status = (int) $data['status'];
        }
        $form->save();

        return ApiResponse::success(['id' => (int) $form->id], '保存成功');
    }

    /**
     * DELETE /api/forms/{id} 删除表单（连同提交数据）。
     */
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('form.delete');

        $form = Form::query()->find($id);
        if ($form === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '表单不存在');
        }

        FormSubmission::query()->where('form_id', $id)->delete();
        $form->delete();

        return ApiResponse::success(null, '已删除');
    }

    /**
     * GET /api/forms/{id}/submissions 提交数据列表。
     */
    public function submissions(int $id): ResponseInterface
    {
        $this->authorize('form.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
        ])->validate();

        $form = Form::query()->find($id);
        if ($form === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '表单不存在');
        }

        $query = FormSubmission::query()->where('form_id', $id)->orderByDesc('id');
        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($data['page_size'] ?? 20)));

        return ApiResponse::success([
            'form' => ['id' => (int) $form->id, 'name' => $form->name, 'code' => $form->code, 'schema' => $form->schema],
            'total' => (clone $query)->count(),
            'page' => $page,
            'page_size' => $pageSize,
            'items' => $query->forPage($page, $pageSize)->get(),
        ]);
    }

    /**
     * GET /api/form/{code} 公开：按标识取已发布的表单 Schema（渲染用）。
     */
    public function publicSchema(string $code): ResponseInterface
    {
        $form = Form::query()->where('code', $code)->where('status', 1)->first();
        if ($form === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '表单不存在或已停用');
        }

        return ApiResponse::success([
            'id' => (int) $form->id,
            'name' => $form->name,
            'description' => $form->description,
            'schema' => $form->schema,
        ]);
    }

    /**
     * POST /api/form/{code}/submit 公开：提交表单数据（按 Schema 做基础校验）。
     */
    public function submit(string $code): ResponseInterface
    {
        $form = Form::query()->where('code', $code)->where('status', 1)->first();
        if ($form === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '表单不存在或已停用');
        }

        $payload = $this->request->all();
        $this->validateAgainstSchema($form, $payload);

        $submission = new FormSubmission();
        $submission->form_id = (int) $form->id;
        $submission->data = $payload;
        $submission->client_ip = ClientIp::fromRequest($this->serverRequest);
        $submission->created_at = date('Y-m-d H:i:s');
        $submission->save();

        return ApiResponse::success(null, '提交成功');
    }

    /**
     * store/update 共用校验。
     */
    private function validatedSchema(?int $exceptId = null): array
    {
        $unique = $exceptId !== null ? '|unique:forms,code,' . $exceptId : '|unique:forms,code';
        $data = $this->validatorFactory->make($this->request->all(), [
            'name' => 'required|string|max:64',
            'code' => 'required|string|max:64|regex:/^[a-z][a-z0-9_-]*$/' . $unique,
            'description' => 'nullable|string|max:255',
            'status' => 'nullable|integer|in:0,1',
            'schema' => 'required|array',
        ], [
            'code.unique' => '表单标识已存在',
            'code.regex' => '表单标识只能是小写字母开头的字母/数字/下划线/中划线',
            'schema.array' => 'schema 必须是对象',
        ])->validate();

        $schema = $data['schema'];
        if (! isset($schema['fields']) || ! is_array($schema['fields'])) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, 'schema.fields 不能为空');
        }

        $codes = [];
        foreach ($schema['fields'] as $field) {
            if (! isset($field['field'], $field['label'], $field['type'])) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, 'schema 字段缺少 field/label/type');
            }
            if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $field['field'])) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, '字段名 ' . $field['field'] . ' 不合法（小写字母开头，字母/数字/下划线）');
            }
            if (isset($codes[$field['field']])) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, '字段名 ' . $field['field'] . ' 重复');
            }
            $codes[$field['field']] = true;
        }

        return [
            'name' => (string) $data['name'],
            'code' => (string) $data['code'],
            'schema' => $schema,
            'description' => (string) ($data['description'] ?? ''),
            'status' => $data['status'] ?? null,
        ];
    }

    /**
     * 按 Schema 校验提交数据：必填、类型、长度。
     */
    private function validateAgainstSchema(Form $form, array $payload): void
    {
        $errors = [];
        foreach ($form->schema['fields'] ?? [] as $field) {
            $key = (string) $field['field'];
            $value = $payload[$key] ?? null;

            $isEmpty = $value === null || $value === '' || $value === [];
            if ($isEmpty) {
                if (! empty($field['required'])) {
                    $errors[] = ($field['label'] ?? $key) . ' 不能为空';
                }
                continue;
            }

            $type = (string) ($field['type'] ?? 'input');
            switch ($type) {
                case 'number':
                    if (! is_numeric($value)) {
                        $errors[] = ($field['label'] ?? $key) . ' 必须是数字';
                    } elseif (isset($field['min']) && $value < $field['min']) {
                        $errors[] = ($field['label'] ?? $key) . ' 不能小于 ' . $field['min'];
                    } elseif (isset($field['max']) && $value > $field['max']) {
                        $errors[] = ($field['label'] ?? $key) . ' 不能大于 ' . $field['max'];
                    }
                    break;
                case 'checkbox':
                    if (! is_array($value)) {
                        $errors[] = ($field['label'] ?? $key) . ' 必须是数组';
                    }
                    break;
                case 'switch':
                    if (! is_bool($value) && ! in_array($value, [0, 1, '0', '1'], true)) {
                        $errors[] = ($field['label'] ?? $key) . ' 必须是布尔值';
                    }
                    break;
                default:
                    if (! is_scalar($value)) {
                        $errors[] = ($field['label'] ?? $key) . ' 类型不正确';
                    } elseif (mb_strlen((string) $value) > 1000) {
                        $errors[] = ($field['label'] ?? $key) . ' 长度不能超过 1000';
                    }
            }
        }

        if ($errors !== []) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, implode('；', $errors));
        }
    }
}
