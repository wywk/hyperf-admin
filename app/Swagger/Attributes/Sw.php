<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Swagger\Attributes;

use OpenApi\Attributes as OA;

/**
 * Swagger 注解轻量封装（Sw* 系列）的公共工具。
 *
 * 在 OA\* 之上提供默认值，手写接口时不再拼长串注解：
 * - 默认自动带 bearerAuth 鉴权与 401/403 通用错误响应（auth:false / errors:[] 可关）；
 * - 默认 200 响应；响应体结构用 responses: [new SwResp(...)] 追加；
 * - 列表接口 page: true 自动加 page/page_size 参数；详情接口 params: [new SwId()]。
 *
 * 典型用法（等价于原来 20+ 行 OA 注解）：
 *
 * #[SwGet(path: '/api/demo', summary: '示例列表', tag: '示例管理', page: true,
 *     params: [new SwQuery('keyword', desc: '关键字')],
 *     errors: [401, 403])]
 * #[SwGet(path: '/api/demo/{id}', summary: '示例详情', tag: '示例管理',
 *     params: [new SwId()],
 *     responses: [new SwResp(desc: '示例详情', props: [new SwProp('id', type: 'integer')])])]
 * #[SwPost(path: '/api/demo', summary: '新增示例', tag: '示例管理',
 *     body: new SwBody(
 *         props: [new SwProp('name', desc: '名称'), new SwProp('price', type: 'number', nullable: true)],
 *         requiredProps: ['name'],
 *     ),
 *     errors: [401, 403, 422])]
 */
final class Sw
{
    /** 错误码 -> 全局共享响应组件名（定义在 App\Swagger\OpenApi） */
    public const ERROR_MAP = [
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'NotFound',
        422 => 'ValidationError',
    ];

    /** 组装完整响应列表：200（可被 extra 中同码响应覆盖）+ 指定错误码（引用共享组件）+ 额外响应 */
    public static function responses(array $errors, ?array $extra = null, string $okDesc = '成功'): array
    {
        $extra = array_values($extra ?? []);
        $custom200 = null;
        $rest = [];
        foreach ($extra as $r) {
            if ($r instanceof OA\Response && ((string) $r->response === '200')) {
                $custom200 = $r;
            } else {
                $rest[] = $r;
            }
        }

        $list = [$custom200 ?? new OA\Response(response: '200', description: $okDesc)];
        foreach ($errors as $code) {
            $name = self::ERROR_MAP[$code] ?? null;
            $list[] = $name !== null
                ? new OA\Response(response: (string) $code, ref: '#/components/responses/' . $name)
                : new OA\Response(response: (string) $code, description: '错误');
        }
        if ($rest !== []) {
            array_push($list, ...$rest);
        }

        return $list;
    }
}
