<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
namespace App\Controller\Api;

use App\Common\ApiCode;
use App\Common\ApiResponse;
use App\Controller\AbstractController;
use App\Controller\Concerns\AuthorizesRequests;
use App\Service\FileManagerService;
use App\Service\Storage\StorageFactory;
use App\Swagger\Attributes\SwDelete;
use App\Swagger\Attributes\SwGet;
use App\Swagger\Attributes\SwId;
use App\Swagger\Attributes\SwPost;
use App\Swagger\Attributes\SwQuery;
use App\Swagger\Attributes\SwResp;
use App\Swagger\Attributes\SwProp;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;
use OpenApi\Attributes as OA;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * 文件管理接口：上传（multipart）、列表、删除、下载。
 *
 * 路由：/api/files（config/routes.php）。
 * 权限码：file.view / file.upload / file.delete。
 */
class FileController extends AbstractController
{
    use AuthorizesRequests;

    #[Inject]
    protected FileManagerService $fileManager;

    #[Inject]
    protected StorageFactory $storageFactory;

    #[OA\Post(
        path: '/api/files/upload',
        operationId: 'fileUpload',
        summary: '上传文件',
        description: 'multipart/form-data 上传单个文件；超过大小限制或扩展名不在允许范围时返回 422。',
        tags: ['文件管理'],
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(properties: [
                    new OA\Property(property: 'file', type: 'string', format: 'binary', description: '待上传的文件'),
                ])
            )
        ),
        responses: [
            new OA\Response(
                response: '200',
                description: '上传成功，返回文件元数据与公开 URL',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'data', properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'name', type: 'string', description: '原始文件名'),
                        new OA\Property(property: 'url', type: 'string', description: '公开访问 URL'),
                        new OA\Property(property: 'size', type: 'integer', description: '字节'),
                    ]),
                ])
            ),
            new OA\Response(response: '401', ref: '#/components/responses/Unauthorized'),
            new OA\Response(response: '403', ref: '#/components/responses/Forbidden'),
            new OA\Response(response: '422', ref: '#/components/responses/ValidationError'),
        ]
    )]
    public function upload(): ResponseInterface
    {
        $this->authorize('file.upload');

        $uploaded = $this->request->getUploadedFiles()['file'] ?? null;
        if ($uploaded === null) {
            return ApiResponse::error(ApiCode::VALIDATION_FAILED, '缺少上传字段 file（multipart/form-data）');
        }

        $user = Context::get('user');
        $file = $this->fileManager->upload(
            (string) $uploaded->getStream()->getContents(),
            $uploaded->getClientFilename() ?? 'unknown',
            $uploaded->getClientMediaType() ?? 'application/octet-stream',
            (int) ($user->id ?? 0),
        );

        return ApiResponse::success($this->fileManager->formatItem($file));
    }

    #[SwGet(path: '/api/files', operationId: 'fileIndex', summary: '文件列表', tag: '文件管理', page: true,
        params: [
            new SwQuery('keyword', desc: '按原始文件名模糊搜索'),
            new SwQuery('ext', desc: '按扩展名过滤，如 png'),
            new SwQuery('user_id', type: 'integer', desc: '按上传者过滤'),
        ],
        responses: [new SwResp(desc: '分页结果：{total, page, page_size, items[]}，item 含 name/url/size/mime/ext/is_image', props: [
            new SwProp('items', type: 'array', items: new OA\Items(type: 'object')),
        ])]
    )]
    public function index(): ResponseInterface
    {
        $this->authorize('file.view');

        $data = $this->request->getQueryParams();
        $result = $this->fileManager->page(
            page: max(1, (int) ($data['page'] ?? 1)),
            pageSize: min(100, max(1, (int) ($data['page_size'] ?? 20))),
            keyword: (string) ($data['keyword'] ?? ''),
            ext: isset($data['ext']) && $data['ext'] !== '' ? (string) $data['ext'] : null,
            userId: isset($data['user_id']) && $data['user_id'] !== '' && $data['user_id'] !== null ? (int) $data['user_id'] : null,
        );

        $result['items'] = array_map(
            fn ($row) => $this->fileManager->formatItem($row),
            $result['items'],
        );

        return ApiResponse::success($result);
    }

    #[SwDelete(path: '/api/files/{id}', operationId: 'fileDestroy', summary: '删除文件', tag: '文件管理',
        desc: '删除物理文件与记录；物理文件已丢失时仅删记录。',
        params: [new SwId()],
    )]
    public function destroy(int $id): ResponseInterface
    {
        $this->authorize('file.delete');

        $this->fileManager->delete($id);

        return ApiResponse::success(['id' => $id]);
    }

    #[SwGet(path: '/api/files/{id}/download', operationId: 'fileDownload', summary: '下载文件', tag: '文件管理',
        desc: '按原始文件名输出文件内容（attachment），需要登录。',
        params: [new SwId()],
    )]
    public function download(int $id): ResponseInterface
    {
        $this->authorize('file.view', 'file.upload');

        $file = \App\Model\File::query()->find($id);
        if ($file === null) {
            return ApiResponse::error(ApiCode::NOT_FOUND, '文件不存在或已删除');
        }

        try {
            $contents = $this->storageFactory->create($file->driver)->get((string) $file->path);
        } catch (RuntimeException) {
            return ApiResponse::error(ApiCode::NOT_FOUND, '物理文件已丢失');
        }

        return $this->response->raw($contents)
            ->withHeader('Content-Type', $file->mime ?: 'application/octet-stream')
            ->withHeader('Content-Disposition', 'attachment; filename="' . rawurlencode((string) $file->name) . '"');
    }
}
