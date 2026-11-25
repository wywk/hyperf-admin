# Swagger 注解封装（Sw* 系列）使用指南

手写接口文档时，用 `App\Swagger\Attributes` 下的 Sw* 注解代替原生的 `OA\*`，一行搞定，自动带上鉴权、通用错误响应、分页参数等默认值。

> 封装类位于 `app/Swagger/Attributes/`，每个类一个文件：
> `SwGet` / `SwPost` / `SwPut` / `SwDelete` / `SwQuery` / `SwId` / `SwBody` / `SwProp` / `SwResp` / `Sw`

## 一、use 引入

```php
use App\Swagger\Attributes\{SwGet, SwPost, SwPut, SwDelete, SwQuery, SwId, SwBody, SwProp, SwResp};
```

按需引入，用到哪个引哪个。

## 二、按接口类型套模板

### 列表接口

自动带 `page` / `page_size` 分页参数 + bearerAuth 鉴权 + 401/403 错误响应。

```php
#[SwGet(path: '/api/mall_order', summary: '订单列表', tag: '订单管理', page: true,
    params: [new SwQuery('status', type: 'integer', desc: '订单状态')])]
public function index(): ResponseInterface
```

### 详情接口

`SwId` 对应路径里的 `{id}`。

```php
#[SwGet(path: '/api/mall_order/{id}', summary: '订单详情', tag: '订单管理',
    params: [new SwId()])]
public function show(int $id): ResponseInterface
```

### 新增接口

`SwBody` 描述 JSON 请求体，`requiredProps` 指定必填字段。

```php
#[SwPost(path: '/api/mall_order', summary: '创建订单', tag: '订单管理',
    body: new SwBody(
        props: [
            new SwProp('goods_id', type: 'integer', desc: '商品ID'),
            new SwProp('quantity', type: 'integer', desc: '数量'),
        ],
        requiredProps: ['goods_id', 'quantity'],
    ))]
public function store(): ResponseInterface
```

### 更新接口

默认错误响应为 401/403/404/422。

```php
#[SwPut(path: '/api/mall_order/{id}', summary: '更新订单', tag: '订单管理',
    params: [new SwId()],
    body: new SwBody(props: [new SwProp('status', type: 'integer', desc: '状态')]))]
public function update(int $id): ResponseInterface
```

### 删除接口

```php
#[SwDelete(path: '/api/mall_order/{id}', summary: '删除订单', tag: '订单管理',
    params: [new SwId()])]
public function destroy(int $id): ResponseInterface
```

### 描述返回内容

`responses` 传 `SwResp`（code=200，会覆盖默认的"成功"响应）：

```php
#[SwGet(path: '/api/demo/{id}', summary: '示例详情', tag: '示例管理',
    params: [new SwId()],
    responses: [new SwResp(desc: '示例详情', props: [
        new SwProp('id', type: 'integer'),
        new SwProp('name'),
        new SwProp('status', type: 'integer', enum: [0, 1]),
    ])])]
public function show(int $id): ResponseInterface
```

`SwResp` 也支持 example：

```php
responses: [new SwResp(desc: '当前用户', example: ['id' => 1, 'username' => 'admin'])]
```

## 三、可选参数一览

| 参数 | 默认值 | 说明 |
|---|---|---|
| `auth` | `true` | `auth: false` 表示公开接口（如 login、文档页），不加登录要求 |
| `errors` | Get/Post: `[401,403]`；Put: `[401,403,404,422]`；Delete: `[401,403,404]` | 按需增减，如 `errors: [401, 422]`；`errors: []` 全关 |
| `page` | `false` | `page: true` 自动加 page / page_size 参数 |
| `desc` | 空 | 接口详细描述 |
| `operationId` | 空 | 一般不传 |
| `params` | `[]` | `SwQuery` / `SwId` / `OA\Parameter` 列表 |
| `body` | `null` | SwPost / SwPut 专用，`new SwBody(...)` |
| `responses` | `[]` | 额外响应列表；其中 code=200 的会覆盖默认 200 |

## 四、Sw* 类速查

| 类 | 用途 | 等价原生写法 |
|---|---|---|
| `SwGet` / `SwPost` / `SwPut` / `SwDelete` | 四种方法注解 | `OA\Get` 等 |
| `SwQuery` | query 参数 | `OA\Parameter(in: 'query')` |
| `SwId` | 路径参数 `{id}` | 同上 |
| `SwBody` | JSON 请求体 | `OA\RequestBody` + `OA\JsonContent` |
| `SwProp` | 请求体 / 响应体字段 | `OA\Property` |
| `SwResp` | 200 响应体 | `OA\Response` |

## 五、导出文档

写完注解**不需要重启容器**（注解只在导出命令运行时扫描），跑一次：

```bash
docker exec hyperf php bin/hyperf.php swagger:export
```

然后打开 http://127.0.0.1:9501/api/docs 查看。

## 六、注意事项

1. **`path` 必须与 `config/routes.php` 里的真实路由一致**——注解只做文档描述，不会生成路由。
2. **同一个 `tag` 值归为一组**——Swagger UI 左侧按 tag 折叠展示，手写接口与生成器生成的接口可以用同一个 tag 合并展示。
3. **一个类一个文件**——Sw* 封装类遵循 PSR-4，请勿把多个注解类合并进一个文件，否则 Composer 自动加载失败，swagger-php 会静默跳过（导出的接口凭空消失）。
4. **`responses` 里不要重复传 200**——`SwResp` 本身是 200，传入会覆盖默认值；如果想再追加其他响应（如 404 自定义文案），直接放在 `responses` 数组里即可。
5. **复杂嵌套结构可与 `OA\Property` 混用**——`SwResp` / `SwBody` 的 `props` 数组里可以同时放 `SwProp`（平铺字段）和原生 `OA\Property`（带嵌套 properties/items 的复杂结构），如项目里的 AuthController、GenController。
6. **部分字段更新的接口**——`SwBody` 传 `required: false`（PUT 默认错误码含 404），如 PermissionController 的 update。
7. 项目里现成示例：`app/Controller/Api/DocsController.php`（公开接口 `auth: false, errors: []` 的写法）；项目内全部控制器已统一使用 Sw* 注解。

## 七、完整示例（改造前后对比）

改造前（原生 OA 注解，约 20 行）：

```php
#[OA\Get(
    path: '/api/mall_order',
    operationId: 'mallOrderIndex',
    summary: '订单列表',
    tags: ['订单管理'],
    security: [['bearerAuth' => []]],
    parameters: [
        new OA\Parameter(name: 'page', in: 'query', required: false, description: '页码，默认 1',
            schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
        new OA\Parameter(name: 'page_size', in: 'query', required: false, description: '每页条数，默认 20',
            schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
    ],
    responses: [
        new OA\Response(response: '200', description: '成功'),
        new OA\Response(response: '401', ref: '#/components/responses/Unauthorized'),
        new OA\Response(response: '403', ref: '#/components/responses/Forbidden'),
    ]
)]
public function index(): ResponseInterface
```

改造后（1 行）：

```php
#[SwGet(path: '/api/mall_order', summary: '订单列表', tag: '订单管理', page: true)]
public function index(): ResponseInterface
```
