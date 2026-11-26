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

namespace App\Controller\Concerns;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\User;
use App\Service\RbacService;
use Hyperf\Context\Context;
use Hyperf\Di\Annotation\Inject;

/**
 * 接口级权限校验。
 *
 * 登录只解决「你是谁」，这里解决「你能不能做这件事」。
 * 中间件只负责认证，具体某个接口要什么权限写在控制器方法里，一眼能看出来。
 */
trait AuthorizesRequests
{
    #[Inject]
    protected RbacService $rbacService;

    /**
     * 传入多个权限码时满足任意一个即可。
     */
    protected function authorize(string ...$codes): void
    {
        $user = Context::get('user');
        if (! $user instanceof User) {
            throw new BusinessException(ApiCode::UNAUTHORIZED, '未登录，请先登录');
        }

        $granted = $this->rbacService->permissionsForRole((string) ($user->role ?: 'user'));

        foreach ($codes as $code) {
            if (in_array($code, $granted, true)) {
                return;
            }
        }

        throw new BusinessException(ApiCode::FORBIDDEN, '没有权限执行该操作');
    }

    /**
     * 非抛异常版权限判断：用于「有权限看全部，没权限看自己」这类分支逻辑。
     */
    protected function can(string ...$codes): bool
    {
        $user = Context::get('user');
        if (! $user instanceof User) {
            return false;
        }

        $granted = $this->rbacService->permissionsForRole((string) ($user->role ?: 'user'));

        foreach ($codes as $code) {
            if (in_array($code, $granted, true)) {
                return true;
            }
        }

        return false;
    }
}
