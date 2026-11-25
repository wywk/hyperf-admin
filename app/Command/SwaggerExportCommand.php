<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/your-name/hyperf-admin
 * @license  MIT
 */
namespace App\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use OpenApi\Generator;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class SwaggerExportCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('swagger:export');
    }

    public function configure(): void
    {
        $this->setDescription('扫描 app/ 内的 OpenAPI Attributes，导出 docs/openapi.json 供 /api/docs 展示');
        $this->addOption('stdout', null, InputOption::VALUE_NONE, '输出到标准输出而不写文件');
    }

    public function handle(): void
    {
        $generator = new Generator();
        $spec = $generator->generate([BASE_PATH . '/app']);

        if ($spec === null) {
            $this->error('未找到任何 OpenAPI Attributes，请确认控制器已标注 #[OA\*] 注解');
            return;
        }

        $json = json_encode(
            json_decode($spec->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($this->input->getOption('stdout')) {
            $this->line($json);
            return;
        }

        $target = BASE_PATH . '/docs/openapi.json';
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }
        file_put_contents($target, $json . "\n");

        $paths = count((array) $spec->paths);
        $this->info("已导出 {$paths} 个路径到 docs/openapi.json");
    }
}
