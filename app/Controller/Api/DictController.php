<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
namespace App\Controller\Api;

use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Service\DictService;
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
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;

/**
 * 数据字典接口：字典类型/字典项管理 + 业务侧按编码读取。
 *
 * 路由：/api/dict（config/routes.php）。
 * 管理接口需要 dict.view / dict.add / dict.edit / dict.delete 权限；
 * items/batch 仅需登录（下拉数据源属于基础能力，任何登录用户都要能用）。
 */
class DictController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected DictService $dict;

    #[SwGet(path: '/api/dict/types', operationId: 'dictTypes', summary: '字典类型列表', tag: '数据字典', page: false,
        desc: '全部类型按 sort 排序，item_count 为该类型字典项数量。',
        params: [new SwQuery('keyword', desc: '按名称/编码模糊搜索')],
        responses: [new SwResp(desc: '类型数组，item 含 name/code/description/sort/status/item_count', props: [
            new SwProp('items', type: 'array', items: new OA\Items(type: 'object')),
        ])]
    )]
    public function types(): ResponseInterface
    {
        $this->authorize('dict.view');

        $keyword = (string) ($this->request->getQueryParams()['keyword'] ?? '');

        return ApiResponse::success(['items' => $this->dict->types($keyword)]);
    }

    #[SwPost(path: '/api/dict/types', operationId: 'dictTypeStore', summary: '新增字典类型', tag: '数据字典',
        body: new SwBody(props: [
            new SwProp('name', desc: '类型名称'),
            new SwProp('code', desc: '类型编码，小写字母开头，业务按此取字典'),
            new SwProp('description', desc: '描述'),
            new SwProp('sort', type: 'integer', desc: '排序'),
            new SwProp('status', type: 'integer', enum: [0, 1], desc: '1=启用 0=停用'),
        ], requiredProps: ['name', 'code']))]
    public function storeType(): ResponseInterface
    {
        $this->authorize('dict.add');

        $type = $this->dict->createType((array) $this->request->getParsedBody());

        return ApiResponse::success($this->dict->formatType($type));
    }

    #[SwPut(path: '/api/dict/types/{id}', operationId: 'dictTypeUpdate', summary: '更新字典类型', tag: '数据字典',
        desc: '编码不可修改；停用类型会同时清掉其缓存。',
        params: [new SwId()],
        body: new SwBody(required: false, props: [
            new SwProp('name', desc: '类型名称'),
            new SwProp('description', desc: '描述'),
            new SwProp('sort', type: 'integer', desc: '排序'),
            new SwProp('status', type: 'integer', enum: [0, 1], desc: '1=启用 0=停用'),
        ]))]
    public function updateType(int $id): ResponseInterface
    {
        $this->authorize('dict.edit');

        $type = $this->dict->updateType($id, (array) $this->request->getParsedBody());

        return ApiResponse::success($this->dict->formatType($type));
    }

    #[SwDelete(path: '/api/dict/types/{id}', operationId: 'dictTypeDestroy', summary: '删除字典类型', tag: '数据字典',
        desc: '同时删除该类型下全部字典项并清缓存。',
        params: [new SwId()])]
    public function destroyType(int $id): ResponseInterface
    {
        $this->authorize('dict.delete');

        $this->dict->deleteType($id);

        return ApiResponse::success(['id' => $id]);
    }

    #[SwGet(path: '/api/dict/types/{id}/items', operationId: 'dictItems', summary: '字典项列表', tag: '数据字典',
        desc: '管理视角：含停用项，按 sort 排序。',
        params: [new SwId()],
        responses: [new SwResp(desc: '字典项数组，item 含 label/value/sort/status/remark', props: [
            new SwProp('items', type: 'array', items: new OA\Items(type: 'object')),
        ])]
    )]
    public function items(int $id): ResponseInterface
    {
        $this->authorize('dict.view');

        return ApiResponse::success(['items' => $this->dict->itemsOf($id)]);
    }

    #[SwPost(path: '/api/dict/types/{id}/items', operationId: 'dictItemStore', summary: '新增字典项', tag: '数据字典',
        params: [new SwId()],
        body: new SwBody(props: [
            new SwProp('label', desc: '显示文本'),
            new SwProp('value', desc: '存储值，同类型内唯一'),
            new SwProp('sort', type: 'integer', desc: '排序'),
            new SwProp('status', type: 'integer', enum: [0, 1], desc: '1=启用 0=停用'),
            new SwProp('remark', desc: '备注'),
        ], requiredProps: ['label', 'value']))]
    public function storeItem(int $id): ResponseInterface
    {
        $this->authorize('dict.add');

        $item = $this->dict->createItem($id, (array) $this->request->getParsedBody());

        return ApiResponse::success($this->dict->formatItem($item));
    }

    #[SwPut(path: '/api/dict/items/{id}', operationId: 'dictItemUpdate', summary: '更新字典项', tag: '数据字典',
        desc: '值不得与同类型下其他项冲突；修改后清所属类型缓存。',
        params: [new SwId()],
        body: new SwBody(required: false, props: [
            new SwProp('label', desc: '显示文本'),
            new SwProp('value', desc: '存储值'),
            new SwProp('sort', type: 'integer', desc: '排序'),
            new SwProp('status', type: 'integer', enum: [0, 1], desc: '1=启用 0=停用'),
            new SwProp('remark', desc: '备注'),
        ]))]
    public function updateItem(int $id): ResponseInterface
    {
        $this->authorize('dict.edit');

        $item = $this->dict->updateItem($id, (array) $this->request->getParsedBody());

        return ApiResponse::success($this->dict->formatItem($item));
    }

    #[SwDelete(path: '/api/dict/items/{id}', operationId: 'dictItemDestroy', summary: '删除字典项', tag: '数据字典',
        params: [new SwId()])]
    public function destroyItem(int $id): ResponseInterface
    {
        $this->authorize('dict.delete');

        $this->dict->deleteItem($id);

        return ApiResponse::success(['id' => $id]);
    }

    #[SwGet(path: '/api/dict/{code}', operationId: 'dictData', summary: '按编码取字典项', tag: '数据字典',
        desc: '仅返回启用类型下的启用项（带缓存），下拉数据源用；只需登录，无需字典管理权限。',
        params: [new SwQuery('code', desc: '字典编码，如 order_status', required: true)],
        responses: [new SwResp(desc: '数组 [{label, value}]，按 sort 排序', props: [
            new SwProp('items', type: 'array', items: new OA\Items(
                properties: [new OA\Property(property: 'label', type: 'string'), new OA\Property(property: 'value', type: 'string')]
            )),
        ])]
    )]
    public function data(string $code): ResponseInterface
    {
        return ApiResponse::success(['items' => $this->dict->items($code)]);
    }

    #[SwPost(path: '/api/dict/batch', operationId: 'dictBatch', summary: '批量取多个字典', tag: '数据字典',
        desc: '页面初始化时一次拉取多个下拉数据源；只需登录。',
        body: new SwBody(props: [
            new SwProp('codes', type: 'array', desc: '字典编码数组', items: new OA\Items(type: 'string')),
        ], requiredProps: ['codes']))]
    public function batch(): ResponseInterface
    {
        $body = (array) $this->request->getParsedBody();
        $codes = array_values(array_filter(array_map('strval', (array) ($body['codes'] ?? []))));

        return ApiResponse::success($this->dict->itemsMany($codes));
    }
}
