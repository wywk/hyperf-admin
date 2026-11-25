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
use App\Swagger\Attributes\SwBody;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwId;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwProp;
use App\Swagger\Attributes\SwPut;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use OpenApi\Attributes as OA;
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
    #[SwGet(path: '/api/forms', operationId: 'formIndex', summary: '表单列表', tag: '表单管理', page: true,
        desc: '表单定义分页列表（不含 schema 大字段）。需要 form.view 权限。',
        params: [
            new SwQuery('keyword', desc: '按名称/标识模糊搜索'),
            new SwQuery('status', type: 'integer', desc: '1=已发布 0=停用', enum: [0, 1]),
        ],
        responses: [new SwResp(desc: '分页结果：{total, page, page_size, items[]}')]
    )]
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
    #[SwGet(path: '/api/forms/{id}', operationId: 'formShow', summary: '表单详情', tag: '表单管理',
        desc: '返回表单定义，含完整 JSON Schema（设计器回显用）。需要 form.view 权限。',
        params: [new SwId()], errors: [401, 403, 404],
        responses: [new SwResp(desc: '表单定义（含 schema.fields 字段数组）')]
    )]
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
    #[SwPost(path: '/api/forms', operationId: 'formStore', summary: '创建表单', tag: '表单管理',
        desc: 'schema 结构：{fields: [{field, label, type, required?, min?, max?, options?}]}，type 支持 input/textarea/number/select/radio/checkbox/date/switch。需要 form.add 权限。',
        body: new SwBody(
            props: [
                new SwProp('name', example: '客户报名'),
                new SwProp('code', desc: '公开提交接口用它定位', example: 'customer_signup'),
                new SwProp('description'),
                new OA\Property(property: 'schema', type: 'object',
                    properties: [
                        new OA\Property(property: 'fields', type: 'array', items: new OA\Items(type: 'object'),
                            description: '字段定义数组，每项含 field/label/type'),
                    ]),
            ],
            requiredProps: ['name', 'code', 'schema'],
        ),
        responses: [new SwResp(desc: '返回新表单 id')]
    )]
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
    #[SwPut(path: '/api/forms/{id}', operationId: 'formUpdate', summary: '更新表单', tag: '表单管理',
        desc: '全量覆盖 name/code/schema/description，可选更新 status。需要 form.edit 权限。',
        params: [new SwId()],
        body: new SwBody(
            props: [
                new SwProp('name'),
                new SwProp('code'),
                new SwProp('description'),
                new SwProp('status', type: 'integer', enum: [0, 1]),
                new SwProp('schema', type: 'object'),
            ],
            requiredProps: ['name', 'code', 'schema'],
        ),
        responses: [new SwResp(desc: '返回表单 id')]
    )]
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
    #[SwDelete(path: '/api/forms/{id}', operationId: 'formDestroy', summary: '删除表单', tag: '表单管理',
        desc: '连同该表单的全部提交数据一并删除。需要 form.delete 权限。',
        params: [new SwId()],
        responses: [new SwResp(desc: '已删除')]
    )]
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
    #[SwGet(path: '/api/forms/{id}/submissions', operationId: 'formSubmissions', summary: '表单提交数据分页', tag: '表单管理', page: true,
        desc: '返回 form 元信息（含 schema，用于渲染列）与提交数据分页。需要 form.view 权限。',
        params: [new SwId()], errors: [401, 403, 404],
        responses: [new SwResp(desc: '{form, total, page, page_size, items[]}')]
    )]
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
    #[SwGet(path: '/api/form/{code}', operationId: 'formPublicSchema', summary: '【公开】按标识取表单 Schema', tag: '表单（公开）', auth: false,
        desc: '无需登录；只返回已发布（status=1）的表单，供任意渲染方（H5/静态页）拉取字段定义。',
        params: [new SwId('code', type: 'string', desc: '表单标识', example: 'customer_signup')],
        errors: [404],
        responses: [new SwResp(desc: '{id, name, description, schema}', props: [
            new OA\Property(property: 'data', type: 'object',
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'schema', type: 'object'),
                ]),
        ])]
    )]
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
    #[SwPost(path: '/api/form/{code}/submit', operationId: 'formPublicSubmit', summary: '【公开】提交表单数据', tag: '表单（公开）', auth: false,
        desc: '无需登录；按表单 Schema 校验必填/类型/数值范围后入库，并记录提交者 IP。',
        params: [new SwId('code', type: 'string', desc: '表单标识', example: 'customer_signup')],
        errors: [404, 422],
        body: new SwBody(
            props: [new SwProp('data', type: 'object', desc: '按 schema.fields 的 field 名作为 key 提交')],
            example: ['name' => '张三', 'phone' => '13800000000', 'age' => 28],
        ),
        responses: [new SwResp(desc: '提交成功')]
    )]
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
