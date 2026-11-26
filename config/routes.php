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
use App\Controller\Api\AuthController;
use App\Controller\Api\CrontabController;
use App\Controller\Api\DashboardController;
use App\Controller\Api\DictController;
use App\Controller\Api\DocsController;
use App\Controller\Api\FileController;
use App\Controller\Api\FormController;
use App\Controller\Api\GenController;
use App\Controller\Api\LoginLogController;
use App\Controller\Api\OperationLogController;
use App\Controller\Api\PermissionController;
use App\Controller\Api\RoleController;
use App\Middleware\AuthMiddleware;
use App\Middleware\OperationLogMiddleware;
use Hyperf\HttpServer\Router\Router;

Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');

Router::get('/favicon.ico', function () {
    return '';
});

// 接口文档（公开）：自带样式的页面 + OpenAPI 3.0 规范
Router::get('/api/docs', [DocsController::class, 'index']);
Router::get('/api/docs/redoc', [DocsController::class, 'redoc']);
Router::get('/api/docs/openapi.json', [DocsController::class, 'json']);

// 公开接口
Router::addGroup('/api', function () {
    Router::post('/auth/login', [AuthController::class, 'login']);
    Router::post('/auth/refresh', [AuthController::class, 'refresh']);

    // 表单公开接口：按标识取已发布 Schema、提交数据（渲染方可以是任意前端/静态页）
    Router::get('/form/{code:[a-z][a-z0-9_-]*}', [FormController::class, 'publicSchema']);
    Router::post('/form/{code:[a-z][a-z0-9_-]*}/submit', [FormController::class, 'submit']);
});

// 需要登录的接口
Router::addGroup('/api', function () {
    Router::get('/auth/me', [AuthController::class, 'me']);
    Router::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Router::post('/auth/logout', [AuthController::class, 'logout']);
    Router::get('/auth/login-logs', [LoginLogController::class, 'index']);
    Router::get('/operation-logs', [OperationLogController::class, 'index']);
    Router::get('/operation-logs/export', [OperationLogController::class, 'export']);

    // 定时任务（接口内部做 crontab.* 权限校验）
    Router::get('/crontabs', [CrontabController::class, 'index']);
    Router::post('/crontabs', [CrontabController::class, 'store']);
    Router::put('/crontabs/{id:\d+}', [CrontabController::class, 'update']);
    Router::delete('/crontabs/{id:\d+}', [CrontabController::class, 'destroy']);
    Router::post('/crontabs/{id:\d+}/run', [CrontabController::class, 'run']);
    Router::get('/crontabs/{id:\d+}/logs', [CrontabController::class, 'logs']);

    // 权限树（接口内部再做 permission.* 权限校验；角色授权弹窗也读它）
    Router::get('/permissions', [PermissionController::class, 'index']);
    Router::post('/permissions', [PermissionController::class, 'store']);
    Router::put('/permissions/{id:\d+}', [PermissionController::class, 'update']);
    Router::delete('/permissions/{id:\d+}', [PermissionController::class, 'destroy']);

    // 角色管理 + 角色授权
    Router::get('/roles', [RoleController::class, 'index']);
    Router::post('/roles', [RoleController::class, 'store']);
    Router::put('/roles/{id:\d+}', [RoleController::class, 'update']);
    Router::delete('/roles/{id:\d+}', [RoleController::class, 'destroy']);
    Router::get('/roles/{id:\d+}/permissions', [RoleController::class, 'permissions']);
    Router::put('/roles/{id:\d+}/permissions', [RoleController::class, 'syncPermissions']);

    // 表单管理（接口内部再做 form.* 权限校验）
    Router::get('/forms', [FormController::class, 'index']);
    Router::post('/forms', [FormController::class, 'store']);
    Router::get('/forms/{id:\d+}', [FormController::class, 'show']);
    Router::put('/forms/{id:\d+}', [FormController::class, 'update']);
    Router::delete('/forms/{id:\d+}', [FormController::class, 'destroy']);
    Router::get('/forms/{id:\d+}/submissions', [FormController::class, 'submissions']);

    // 代码生成器（接口内部做 gen.* 权限校验）
    Router::get('/gen/tables', [GenController::class, 'tables']);
    Router::get('/gen/tables/{table:[a-zA-Z_][a-zA-Z0-9_]*}/fields', [GenController::class, 'fields']);
    Router::post('/gen/preview', [GenController::class, 'preview']);
    Router::post('/gen/generate', [GenController::class, 'generate']);

    // 文件管理（接口内部做 file.* 权限校验）
    Router::post('/files/upload', [FileController::class, 'upload']);
    Router::get('/files', [FileController::class, 'index']);
    Router::delete('/files/{id:\d+}', [FileController::class, 'destroy']);
    Router::get('/files/{id:\d+}/download', [FileController::class, 'download']);

    // 数据字典（管理接口内部做 dict.* 权限校验；items/batch 只需登录）
    // 注意：{code} 路由要放在 types/items 之后，避免吞掉两段路径
    Router::get('/dict/types', [DictController::class, 'types']);
    Router::post('/dict/types', [DictController::class, 'storeType']);
    Router::put('/dict/types/{id:\d+}', [DictController::class, 'updateType']);
    Router::delete('/dict/types/{id:\d+}', [DictController::class, 'destroyType']);
    Router::get('/dict/types/{id:\d+}/items', [DictController::class, 'items']);
    Router::post('/dict/types/{id:\d+}/items', [DictController::class, 'storeItem']);
    Router::put('/dict/items/{id:\d+}', [DictController::class, 'updateItem']);
    Router::delete('/dict/items/{id:\d+}', [DictController::class, 'destroyItem']);
    Router::post('/dict/batch', [DictController::class, 'batch']);
    Router::get('/dict/{code:[a-z][a-z0-9_]*}', [DictController::class, 'data']);
    // 操作日志中间件放 AuthMiddleware 之后：保证 user 已解析进协程上下文
}, ['middleware' => [AuthMiddleware::class, OperationLogMiddleware::class]]);

// 本地上传文件的公开访问（文件名为随机 UUID，路径由 LocalDriver::absolute 校验防穿越）
Router::get('/uploads/{path:.*}', [\App\Controller\UploadServeController::class, 'show']);
























// [gen:mall_category] start（代码生成器维护，勿手改）
Router::addGroup('/api', function () {
    Router::get('/mall_category', [\App\Controller\Api\MallCategoryController::class, 'index']);
    Router::post('/mall_category', [\App\Controller\Api\MallCategoryController::class, 'store']);
    Router::get('/mall_category/{id:\d+}', [\App\Controller\Api\MallCategoryController::class, 'show']);
    Router::put('/mall_category/{id:\d+}', [\App\Controller\Api\MallCategoryController::class, 'update']);
    Router::delete('/mall_category/{id:\d+}', [\App\Controller\Api\MallCategoryController::class, 'destroy']);
}, ['middleware' => [AuthMiddleware::class]]);
// [gen:mall_category] end

// [gen:mall_goods] start（代码生成器维护，勿手改）
Router::addGroup('/api', function () {
    Router::get('/mall_goods', [\App\Controller\Api\MallGoodsController::class, 'index']);
    Router::post('/mall_goods', [\App\Controller\Api\MallGoodsController::class, 'store']);
    Router::get('/mall_goods/{id:\d+}', [\App\Controller\Api\MallGoodsController::class, 'show']);
    Router::put('/mall_goods/{id:\d+}', [\App\Controller\Api\MallGoodsController::class, 'update']);
    Router::delete('/mall_goods/{id:\d+}', [\App\Controller\Api\MallGoodsController::class, 'destroy']);
}, ['middleware' => [AuthMiddleware::class]]);
// [gen:mall_goods] end

// [gen:mall_goods_sku] start（代码生成器维护，勿手改）
Router::addGroup('/api', function () {
    Router::get('/mall_goods_sku', [\App\Controller\Api\MallGoodsSkuController::class, 'index']);
    Router::post('/mall_goods_sku', [\App\Controller\Api\MallGoodsSkuController::class, 'store']);
    Router::get('/mall_goods_sku/{id:\d+}', [\App\Controller\Api\MallGoodsSkuController::class, 'show']);
    Router::put('/mall_goods_sku/{id:\d+}', [\App\Controller\Api\MallGoodsSkuController::class, 'update']);
    Router::delete('/mall_goods_sku/{id:\d+}', [\App\Controller\Api\MallGoodsSkuController::class, 'destroy']);
}, ['middleware' => [AuthMiddleware::class]]);
// [gen:mall_goods_sku] end

// [gen:mall_order] start（代码生成器维护，勿手改）
Router::addGroup('/api', function () {
    Router::get('/mall_order', [\App\Controller\Api\MallOrderController::class, 'index']);
    Router::post('/mall_order', [\App\Controller\Api\MallOrderController::class, 'store']);
    Router::get('/mall_order/{id:\d+}', [\App\Controller\Api\MallOrderController::class, 'show']);
    Router::put('/mall_order/{id:\d+}', [\App\Controller\Api\MallOrderController::class, 'update']);
    Router::delete('/mall_order/{id:\d+}', [\App\Controller\Api\MallOrderController::class, 'destroy']);
}, ['middleware' => [AuthMiddleware::class]]);
// [gen:mall_order] end

// [gen:mall_order_item] start（代码生成器维护，勿手改）
Router::addGroup('/api', function () {
    Router::get('/mall_order_item', [\App\Controller\Api\MallOrderItemController::class, 'index']);
    Router::post('/mall_order_item', [\App\Controller\Api\MallOrderItemController::class, 'store']);
    Router::get('/mall_order_item/{id:\d+}', [\App\Controller\Api\MallOrderItemController::class, 'show']);
    Router::put('/mall_order_item/{id:\d+}', [\App\Controller\Api\MallOrderItemController::class, 'update']);
    Router::delete('/mall_order_item/{id:\d+}', [\App\Controller\Api\MallOrderItemController::class, 'destroy']);
}, ['middleware' => [AuthMiddleware::class]]);
// [gen:mall_order_item] end

// [gen:product] start（代码生成器维护，勿手改）
Router::addGroup('/api', function () {
    Router::get('/product', [\App\Controller\Api\ProductController::class, 'index']);
    Router::get('/product/export', [\App\Controller\Api\ProductController::class, 'export']);
    Router::post('/product/import', [\App\Controller\Api\ProductController::class, 'import']);
    Router::post('/product', [\App\Controller\Api\ProductController::class, 'store']);
    Router::get('/product/{id:\d+}', [\App\Controller\Api\ProductController::class, 'show']);
    Router::put('/product/{id:\d+}', [\App\Controller\Api\ProductController::class, 'update']);
    Router::delete('/product/{id:\d+}', [\App\Controller\Api\ProductController::class, 'destroy']);
    Router::post('/product/batch-delete', [\App\Controller\Api\ProductController::class, 'batchDestroy']);
}, ['middleware' => [AuthMiddleware::class]]);
// [gen:product] end
