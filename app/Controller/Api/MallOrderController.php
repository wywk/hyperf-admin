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
use App\Model\MallOrder;
use App\Service\MallOrderService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * MallOrder CRUD 接口（由代码生成器生成）。
 *
 * 路由：/api/mall_order（config/routes.php 里的 [gen:mall_order] 标记块）。
 * 权限码：mall_order.view/.add/.edit/.delete（生成器已注册到权限树）。
 */
class MallOrderController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected ValidatorFactoryInterface $validatorFactory;

    public function __construct(protected MallOrderService $service)
    {
    }

    public function index(): ResponseInterface
    {
        $this->authorize('mall_order.view');

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
        $this->authorize('mall_order.view');

        $model = MallOrder::query()->find($id);
        if ($model === null) {
            return ApiResponse::fail(ApiCode::NOT_FOUND, '记录不存在');
        }

        return ApiResponse::success($model);
    }

    public function store(): ResponseInterface
    {
        $this->authorize('mall_order.add');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();

        return ApiResponse::success(['id' => (int) $this->service->create($data)->id], '创建成功');
    }

    public function update(int $id): ResponseInterface
    {
        $this->authorize('mall_order.edit');

        $data = $this->validatorFactory->make($this->request->all(), $this->rules())->validate();
        $this->service->update($id, $data);

        return ApiResponse::success(['id' => $id], '保存成功');
    }

    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('mall_order.delete');

        $this->service->delete($id);

        return ApiResponse::success(null, '已删除');
    }

    private function rules(): array
    {
        return [
            'order_no' => 'required|string|max:191',
            'receiver' => 'nullable|string|max:191',
            'phone' => 'nullable|string|max:191',
            'amount' => 'required|numeric',
            'status' => 'nullable|in:0,1,2,3,4',
            'user_id' => 'nullable|numeric',
            'address' => 'nullable|string|max:191',
            'remark' => 'nullable|string|max:2000',
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
        'order_no' => $data['order_no'] ?? null,
        'receiver' => $data['receiver'] ?? null,
        'phone' => $data['phone'] ?? null,
        'status' => $data['status'] ?? null,
        ];
    }
}
