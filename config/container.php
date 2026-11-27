<?php

/**
 * Initialize a dependency injection container that implemented PSR-11 and return the container.
 */

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
use Hyperf\Context\ApplicationContext;
use Hyperf\Database\Model\Register;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSourceFactory;
use Psr\EventDispatcher\EventDispatcherInterface;

$container = new Container((new DefinitionSourceFactory())());

// 把主事件分发器绑给模型层：激活模型事件（字段级审计 / 模型监听器依赖）。
// 放在容器构建处保证 HTTP 服务、CLI、测试三种环境一致生效。
if ($container->has(EventDispatcherInterface::class)) {
    Register::setEventDispatcher($container->get(EventDispatcherInterface::class));
}

return ApplicationContext::setContainer($container);
