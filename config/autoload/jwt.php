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
use function Hyperf\Support\env;

return [
    // 签名密钥，务必在 .env 中配置，生产环境不要使用默认值
    'secret' => (string) env('JWT_SECRET', ''),
    'algo' => (string) env('JWT_ALGO', 'HS256'),
    'issuer' => (string) env('JWT_ISSUER', 'hyperf-skeleton'),
    // access_token 有效期（秒）
    'ttl' => (int) env('JWT_TTL', 7200),
    // refresh_token 有效期（秒）
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 604800),
];
