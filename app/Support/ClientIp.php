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

namespace App\Support;

use Psr\Http\Message\ServerRequestInterface;

use function Hyperf\Config\config;

/**
 * 取客户端真实 IP。
 * 默认只信任 TCP 层的 remote_addr；只有在请求必经自有反向代理时才打开 TRUST_PROXY，
 * 否则 X-Forwarded-For 可被客户端任意伪造，登录限流会被轻易绕过。
 */
final class ClientIp
{
    public static function fromRequest(ServerRequestInterface $request): string
    {
        if ((bool) config('security.trust_proxy', false)) {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');
            if ($forwarded !== '') {
                $ip = trim(explode(',', $forwarded)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }

            $realIp = $request->getHeaderLine('X-Real-IP');
            if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP) !== false) {
                return $realIp;
            }
        }

        $serverParams = $request->getServerParams();

        return (string) ($serverParams['remote_addr'] ?? 'unknown');
    }
}
