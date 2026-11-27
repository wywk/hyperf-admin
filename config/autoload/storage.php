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
/*
 * 文件存储配置。
 *
 * driver：存储驱动，默认 local（写入本地磁盘，经 /uploads 公开访问）。
 * 扩展其他驱动（如阿里云 OSS / 腾讯云 COS）：实现 App\Service\Storage\DriverInterface
 * 并在此注册驱动名即可，上层业务与文件表结构不变。
 */
return [
    'driver' => getenv('STORAGE_DRIVER') ?: 'local',

    'local' => [
        // 物理存储根目录（相对 BASE_PATH）
        'root' => getenv('STORAGE_LOCAL_ROOT') ?: 'storage/uploads',
        // 公开访问 URL 前缀（与 routes.php 里的静态路由一致）
        'url_prefix' => getenv('STORAGE_URL_PREFIX') ?: '/uploads',
    ],

    // 单文件大小上限（字节），默认 10MB；运行环境仍受 php.ini post_max_size 约束
    'max_size' => (int) (getenv('STORAGE_MAX_SIZE') ?: 10485760),

    // 允许上传的扩展名（小写），空数组 = 不限制
    'allow_ext' => array_map(
        'strtolower',
        array_filter(explode(',', (string) (getenv('STORAGE_ALLOW_EXT') ?: '')))
    ),

    // 禁止上传的扩展名（无论 allow_ext 是否限制都生效）
    'deny_ext' => ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'asp', 'aspx', 'jsp', 'exe', 'bat', 'sh'],
];
