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
use App\Model\MallGoods;
use App\Service\MallGoodsService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * MallGoods CRUD 接口（由代码生成器生成）。
 *
 * 路由：/api/mall_goods（config/routes.php 里的 [gen:mall_goods] 标记块）。
 * 权限码：mall_goods.view/.add/.edit/.delete（生成器已注册到权限树）。
 */
class MallGoodsController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected MallGoodsService $service)
    {
    }

    public function index(): ResponseInterface
    {
        $this->authorize('mall_goods.view');

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

    public function show(int $id): ResponseInterface
    {
        $this->authorize('mall_goods.view');

        $model = MallGoods::query()->find($id);
        if ($model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success($model);
    }

    public function store(): ResponseInterface
    {
        $this->authorize('mall_goods.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    public function update(int $id): ResponseInterface
    {
        $this->authorize('mall_goods.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('mall_goods.delete');

        $this->service->delete($id);

        return ApiResponse::success(null, '已删除');
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'category_id' => 'required|numeric',
            'price' => 'required|numeric',
            'stock' => 'nullable|numeric',
            'status' => 'nullable|string|max:191',
            'cover' => 'nullable|string|max:191',
            'summary' => 'nullable|string|max:2000',
        ];
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

    /** 从请求里提取搜索条件 */
    private function searchFilters(array $data): array
    {
        return [
        'name' => $data['name'] ?? null,
        'category_id' => $data['category_id'] ?? null,
        'price' => $data['price'] ?? null,
        'status' => $data['status'] ?? null,
        ];
    }
}
