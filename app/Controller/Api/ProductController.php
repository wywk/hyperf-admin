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
use App\Model\Product;
use App\Exception\BusinessException;
use App\Service\DictService;
use App\Service\ProductService;
use App\Service\Excel\ExcelService;
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
use Psr\Http\Message\ResponseInterface;

/**
 * Product CRUD 接口（由代码生成器生成）。
 *
 * 路由：/api/product（config/routes.php 里的 [gen:product] 标记块）。
 * 权限码：product.view/.add/.edit/.delete（生成器已注册到权限树）。
 */
class ProductController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    #[Inject]
    protected ExcelService $excel;

    #[Inject]
    protected DictService $dict;
    public function __construct(protected ProductService $service)
    {
    }

    #[SwGet(path: '/api/product', operationId: 'productIndex', summary: '示例商品列表', tag: '示例商品', page: true,
        params: [
        new SwQuery('name', type: 'string', desc: '名称'),
        new SwQuery('price', type: 'number', desc: '价格'),
        new SwQuery('status', type: 'string', desc: '状态'),
        ],
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('product.view');

        $data = $this->validatorFactory->make($this->request->all(), [
            'page' => 'nullable|integer|min:1',
            'page_size' => 'nullable|integer|between:1,100',
        ] + $this->searchRules())->validate();

        $page = max(1, (int) ($data['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($data['page_size'] ?? 20)));

        return ApiResponse::success($this->service->paginate(
            array_filter($this->searchFilters($data), static fn ($v) => $v !== null && $v !== ''),
            $page,
            $pageSize
        ));
    }

    #[SwGet(path: '/api/product/{id}', operationId: 'productShow', summary: '示例商品详情', tag: '示例商品',
        params: [new SwId()],
    )]
    public function show(int $id): ResponseInterface
    {
        $this->authorize('product.view');

        $model = Product::query()->find($id);
        if ($model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success($model);
    }

    #[SwPost(path: '/api/product', operationId: 'productStore', summary: '新增示例商品', tag: '示例商品',
        body: new SwBody(
            props: [
            new SwProp('name', type: 'string', desc: '名称'),
            new SwProp('price', type: 'number', desc: '价格'),
            new SwProp('stock', type: 'number', desc: '库存'),
            new SwProp('status', type: 'string', desc: '状态（字典 common_status）'),
            ],
            requiredProps: ['name', 'price'],
        ),
    )]
    public function store(): ResponseInterface
    {
        $this->authorize('product.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();
        $data = $this->validateDictValues($data);

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    #[SwPut(path: '/api/product/{id}', operationId: 'productUpdate', summary: '更新示例商品', tag: '示例商品',
        desc: '支持部分字段更新（传哪些改哪些）。',
        params: [new SwId()],
        body: new SwBody(
            props: [
            new SwProp('name', type: 'string', desc: '名称'),
            new SwProp('price', type: 'number', desc: '价格'),
            new SwProp('stock', type: 'number', desc: '库存'),
            new SwProp('status', type: 'string', desc: '状态（字典 common_status）'),
            ],
            required: false,
        ),
    )]
    public function update(int $id): ResponseInterface
    {
        $this->authorize('product.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rulesForUpdate())->validate();
        $data = $this->validateDictValues($data);
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    #[SwDelete(path: '/api/product/{id}', operationId: 'productDestroy', summary: '删除示例商品', tag: '示例商品',
        params: [new SwId()],
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('product.delete');

        $this->service->delete($id);

        return ApiResponse::success(null, '已删除');
    }

    #[SwPost(path: '/api/product/batch-delete', operationId: 'productBatchDestroy', summary: '批量删除示例商品', tag: '示例商品',
        desc: '按 ID 数组批量删除，返回实际删除数量。需要 product.delete 权限。',
        body: new SwBody(props: [
            new SwProp('ids', type: 'array', desc: '待删除的 ID 数组（1-100 个）'),
        ], requiredProps: ['ids']),
        responses: [new SwResp(desc: '删除数量', props: [
            new SwProp('deleted', type: 'integer'),
        ])]
    )]
    public function batchDestroy(): ResponseInterface
    {
        $this->authorize('product.delete');

        $data = $this->validatorFactory->make($this->request->all(), [
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'integer|min:1',
        ], [
            'ids.required' => 'ids 不能为空',
            'ids.array' => 'ids 必须是数组',
            'ids.max' => '单次最多删除 100 条',
        ])->validate();

        $deleted = $this->service->deleteByIds(array_map('intval', $data['ids']));

        return ApiResponse::success(['deleted' => $deleted], '已删除');
    }

    #[SwGet(path: '/api/product/export', operationId: 'productExport', summary: '导出示例商品 Excel', tag: '示例商品',
        desc: '与列表共用搜索参数，导出全部匹配记录为 xlsx（按 id 升序分批读取）。',
        params: [
        new SwQuery('name', type: 'string', desc: '名称'),
        new SwQuery('price', type: 'number', desc: '价格'),
        new SwQuery('status', type: 'string', desc: '状态'),
        ],
    )]
    public function export(): ResponseInterface
    {
        $this->authorize('product.view');

        $data = $this->validatorFactory->make($this->request->all(), $this->searchRules())->validate();
        $filters = array_filter($this->searchFilters($data), static fn ($v) => $v !== null && $v !== '');

        $binary = $this->excel->export(
            'product.xlsx',
            $this->exportColumns(),
            $this->service->exportRows($filters)
        );

        return $this->response->raw($binary)
            ->withHeader('Content-Type', ExcelService::XLSX_MIME)
            ->withHeader('Content-Disposition', 'attachment; filename="product.xlsx"');
    }

    #[SwPost(path: '/api/product/import', operationId: 'productImport', summary: '导入示例商品 Excel', tag: '示例商品',
        desc: '上传 xlsx（multipart，字段 file；表头与导出列一致），逐行校验后批量入库，返回成功数与失败行明细。',
        body: new SwBody(
            props: [
                new SwProp('file', type: 'string', format: 'binary', desc: 'xlsx 文件'),
            ],
            requiredProps: ['file'],
        ),
    )]
    public function import(): ResponseInterface
    {
        $this->authorize('product.add');

        $uploaded = $this->request->getUploadedFiles()['file'] ?? null;
        if ($uploaded === null) {
            return ApiResponse::fail(ApiCode::VALIDATION_FAILED, '缺少上传字段 file（multipart/form-data）');
        }
        if (! str_ends_with(strtolower((string) $uploaded->getClientFilename()), '.xlsx')) {
            return ApiResponse::fail(ApiCode::VALIDATION_FAILED, '仅支持 .xlsx 文件');
        }

        // 表头文本 => 字段名（与导出列一致：导出的表格填上数据即可直接回导）
        $headerMap = array_flip($this->exportColumns());

        $rows = $this->excel->parse((string) $uploaded->getStream()->getContents());
        $failed = [];
        $valid = [];
        foreach ($rows as $index => $parsed) {
            $row = [];
            foreach ($parsed as $header => $value) {
                $field = $headerMap[$header] ?? null;
                if ($field !== null) {
                    $row[$field] = $value;
                }
            }

            $validator = $this->validatorFactory->make($row, $this->rules());
            if ($validator->fails()) {
                $failed[] = ['row' => $index + 2, 'errors' => $validator->errors()->all()];
                continue;
            }
            $valid[] = $validator->validated();
        }

        $success = $valid === [] ? 0 : $this->service->insertRows($valid);

        return ApiResponse::success([
            'total' => count($rows),
            'success' => $success,
            'failed' => $failed,
        ], $failed === [] ? '导入完成' : '导入完成（部分行校验失败）');
    }

    /** 字典字段值校验：与字典项比对（DictService 走缓存） */
    private function validateDictValues(array $data): array
    {
        $checks = [
        'status' => 'common_status',
        ];
        foreach ($checks as $field => $code) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $allowed = array_column($this->dict->items($code), 'value');
            if (! in_array((string) $value, $allowed, true)) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, "{$field} 的值不在字典 {$code} 范围内");
            }
        }

        return $data;
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:64',
            'price' => 'required|numeric',
            'stock' => 'nullable|numeric',
            'status' => 'nullable|string|max:191',
        ];
    }

    /** 更新支持部分字段提交：required 放宽为 nullable，传了才校验 */
    private function rulesForUpdate(): array
    {
        return array_map(
            static fn (string $rule): string => str_replace('required', 'nullable', $rule),
            $this->rules()
        );
    }

    /** 搜索字段的校验规则（生成器按配置展开；这里全部放宽为 nullable） */
    private function searchRules(): array
    {
        $rules = [];
        foreach ($this->searchFieldNames() as $name) {
            $rules[$name] = 'nullable';
        }

        return $rules;
    }

    private function searchFieldNames(): array
    {
        return array_keys($this->searchFilters([]));
    }

    /** 导出列：字段 => 表头文本（配置的所有业务字段） */
    private function exportColumns(): array
    {
        return [
        'name' => '名称',
        'price' => '价格',
        'stock' => '库存',
        'status' => '状态',
        ];
    }

    /** 从请求里提取搜索条件 */
    private function searchFilters(array $data): array
    {
        return [
        'name' => $data['name'] ?? null,
        'price' => $data['price'] ?? null,
        'status' => $data['status'] ?? null,
        ];
    }
}
