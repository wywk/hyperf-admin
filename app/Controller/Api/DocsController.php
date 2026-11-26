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

namespace App\Controller\Api;

use App\Controller\AbstractController;
use App\Swagger\Attributes\SwGet;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * 接口文档：docs/openapi.json 是唯一数据源，本控制器只做两件事 —— 输出 spec、渲染内嵌的文档页。
 * 文档页不依赖任何外网 CDN（离线可用），也可以把 openapi.json 直接丢给 Swagger UI / Postman 使用。
 */
class DocsController extends AbstractController
{
    private const SPEC_FILE = BASE_PATH . '/docs/openapi.json';

    private const PAGE_FILE = BASE_PATH . '/docs/api-docs.html';

    private const REDOC_FILE = BASE_PATH . '/docs/api-docs-redoc.html';

    /**
     * GET /api/docs/redoc：Redoc 视图（渲染器走 CDN，离线时页面内提示回退）.
     */
    #[SwGet(path: '/api/docs/redoc', operationId: 'docsRedoc', summary: '【公开】接口文档页（Redoc 视图）',
        tag: '文档', auth: false, errors: [],
        desc: 'Redoc 渲染的文档视图（渲染器走 CDN）；离线请用 /api/docs 内置页面。',
        responses: [new \App\Swagger\Attributes\SwResp(desc: 'HTML 文档页')])]
    public function redoc(): ResponseInterface
    {
        $page = @file_get_contents(self::REDOC_FILE);
        if ($page === false) {
            throw new RuntimeException('Redoc 文档页缺失：docs/api-docs-redoc.html');
        }

        return $this->response->html($page);
    }

    /**
     * GET /api/docs：自带样式的文档页.
     */
    #[SwGet(path: '/api/docs', operationId: 'docsIndex', summary: '【公开】接口文档页',
        tag: '文档', auth: false, errors: [],
        desc: '离线可用的 Swagger UI 文档页（数据源为 /api/docs/openapi.json）。',
        responses: [new \App\Swagger\Attributes\SwResp(desc: 'HTML 文档页')])]
    public function index(): ResponseInterface
    {
        $page = @file_get_contents(self::PAGE_FILE);
        if ($page === false) {
            throw new RuntimeException('接口文档页缺失：docs/api-docs.html');
        }

        return $this->response->html($page);
    }

    /**
     * GET /api/docs/openapi.json：OpenAPI 3.0 规范.
     */
    #[SwGet(path: '/api/docs/openapi.json', operationId: 'docsJson', summary: '【公开】OpenAPI 3.0 规范 JSON',
        tag: '文档', auth: false, errors: [],
        desc: '由 `php bin/hyperf.php swagger:export` 扫描控制器 Attributes 生成；可直接导入 Swagger UI / Postman / Apifox。',
        responses: [new \App\Swagger\Attributes\SwResp(desc: 'OpenAPI 3.0 JSON')])]
    public function json(): ResponseInterface
    {
        $raw = @file_get_contents(self::SPEC_FILE);
        if ($raw === false) {
            throw new RuntimeException('接口规范缺失：docs/openapi.json');
        }

        $spec = json_decode($raw, true);
        if (! is_array($spec)) {
            throw new RuntimeException('openapi.json 解析失败：' . json_last_error_msg());
        }

        return $this->response->json($spec);
    }
}
