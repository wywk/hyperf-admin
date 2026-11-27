<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Swagger;

use OpenApi\Attributes as OA;

/**
 * OpenAPI 全局规范定义（仅承载 swagger-php Attributes，不承载业务）。
 *
 * 执行 `php bin/hyperf.php swagger:export` 扫描 app/ 生成 docs/openapi.json，
 * /api/docs 的 Swagger UI 读取该文件展示文档。
 */
#[OA\Info(
    version: '1.0.0',
    title: 'Hyperf Admin API',
    description: 'Hyperf Admin 后台管理接口文档：认证、RBAC（树形菜单+按钮权限）、登录审计、表单设计器、代码生成器与商城演示模块。'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'JWT',
    description: '通过 /api/auth/login 获取 access_token，请求头携带 `Authorization: Bearer {token}`'
)]
#[OA\Components(responses: [
    new OA\Response(
        response: 'Unauthorized',
        description: '未登录或 token 已失效',
        content: new OA\JsonContent(
            example: ['code' => 401, 'message' => '未登录或登录已过期', 'data' => null]
        )
    ),
    new OA\Response(
        response: 'Forbidden',
        description: '无权限（当前账号缺少对应按钮权限）',
        content: new OA\JsonContent(
            example: ['code' => 403, 'message' => '无权限访问', 'data' => null]
        )
    ),
    new OA\Response(
        response: 'NotFound',
        description: '资源不存在',
        content: new OA\JsonContent(
            example: ['code' => 404, 'message' => '记录不存在', 'data' => null]
        )
    ),
    new OA\Response(
        response: 'ValidationError',
        description: '参数校验失败',
        content: new OA\JsonContent(
            example: ['code' => 422, 'message' => '参数错误：xxx', 'data' => null]
        )
    ),
])]
abstract class OpenApi
{
}

/**
 * 代码生成器的生成配置（preview / generate 共用请求体）。
 */
#[OA\Schema(
    schema: 'GenConfig',
    title: '代码生成配置',
    required: ['table', 'module', 'fields'],
    properties: [
        new OA\Property(property: 'table', type: 'string', example: 'ha_mall_goods', description: '物理表名'),
        new OA\Property(property: 'module', type: 'string', example: 'mall_goods', description: '模块标识（路由/权限码/前端文件名）'),
        new OA\Property(property: 'controller', type: 'string', example: 'MallGoods', description: '类名前缀，缺省按模块名 StudlyCase'),
        new OA\Property(
            property: 'menu',
            type: 'object',
            properties: [
                new OA\Property(property: 'parent_id', type: 'integer', description: '菜单父节点 ID，0 为顶级目录'),
                new OA\Property(property: 'name', type: 'string', example: '商品管理'),
                new OA\Property(property: 'path', type: 'string', example: 'mall/goods', description: '前端路由 path（建议与菜单一致）'),
                new OA\Property(property: 'icon', type: 'string', example: 'Goods'),
                new OA\Property(property: 'sort', type: 'integer'),
            ]
        ),
        new OA\Property(property: 'fields', type: 'array', items: new OA\Items(
            required: ['name', 'label', 'form_type'],
            properties: [
                new OA\Property(property: 'name', type: 'string', example: 'name'),
                new OA\Property(property: 'label', type: 'string', example: '商品名称'),
                new OA\Property(
                    property: 'form_type',
                    type: 'string',
                    enum: ['input', 'textarea', 'number', 'select', 'radio', 'checkbox', 'switch', 'date']
                ),
                new OA\Property(property: 'required', type: 'boolean'),
                new OA\Property(property: 'list', type: 'boolean', description: '是否出现在列表'),
                new OA\Property(property: 'search', type: 'boolean', description: '是否作为搜索条件'),
                new OA\Property(property: 'form', type: 'boolean', description: '是否出现在表单'),
                new OA\Property(
                    property: 'options',
                    type: 'array',
                    items: new OA\Items(type: 'string'),
                    description: 'select/radio 选项，支持 "1=上架" 值=标签写法'
                ),
            ]
        )),
    ]
)]
abstract class GenConfigSchema
{
}
