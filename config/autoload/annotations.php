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
return [
    'scan' => [
        'paths' => [
            BASE_PATH . '/app',
        ],
        'ignore_annotations' => [
            'mixin',
            // PHP 8.3 原生属性：在 8.1 运行环境且无 polyfill 时，扫描器会因
            // class_exists('Override') 为 false 而抛 NotFoundException（如 vendor 里的 swagger-php）
            'Override',
        ],
    ],
];
