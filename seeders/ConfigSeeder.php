<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
use App\Model\Config;
use Hyperf\Database\Seeders\Seeder;

/**
 * 系统配置的初始键。幂等：已存在的键不会覆盖你在后台改过的值。
 *
 * php bin/hyperf.php db:seed
 */
class ConfigSeeder extends Seeder
{
    /**
     * @var array<int, array{group: string, key: string, value: string, type: string, name: string, description: string, sort: int}>
     */
    private const DEFAULTS = [
        ['basic', 'site_name', 'Hyperf Admin', Config::TYPE_STRING, '站点名称', '显示在登录页与浏览器标题', 10],
        ['basic', 'site_description', '开箱即用的 Hyperf 后台管理系统', Config::TYPE_STRING, '站点描述', '登录页副标题', 20],
        ['basic', 'page_size', '20', Config::TYPE_NUMBER, '默认分页大小', '列表页默认每页条数（1-100）', 30],

        ['security', 'login_max_attempts', '5', Config::TYPE_NUMBER, '登录失败次数上限', '同一 IP + 用户名连续失败多少次后锁定，默认 5', 10],
        ['security', 'login_decay_seconds', '900', Config::TYPE_NUMBER, '登录锁定时长（秒）', '达到失败上限后锁定的秒数，默认 900（15 分钟）', 20],
        ['security', 'crontab_fail_alert_threshold', '3', Config::TYPE_NUMBER, '任务失败告警阈值', '定时任务连续失败多少次触发一次告警', 30],
        ['security', 'alert_webhook_url', '', Config::TYPE_STRING, '告警 Webhook', '任务告警 POST 的地址，留空则只写日志', 40],

        ['audit', 'login_log_enabled', '1', Config::TYPE_BOOLEAN, '记录登录日志', '关闭后不再写 login_logs（不建议）', 10],
        ['audit', 'operation_log_enabled', '1', Config::TYPE_BOOLEAN, '记录操作日志', '关闭后不再写 operation_logs（不建议）', 20],
        ['audit', 'login_log_retention_days', '90', Config::TYPE_NUMBER, '登录日志保留天数', '超过天数的记录可被清理任务删除', 30],
        ['audit', 'operation_log_retention_days', '180', Config::TYPE_NUMBER, '操作日志保留天数', '超过天数的记录可被清理任务删除', 40],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as [$group, $key, $value, $type, $name, $description, $sort]) {
            /** @var null|Config $config */
            $config = Config::query()->where('key', $key)->first();
            if ($config === null) {
                Config::query()->create([
                    'group' => $group,
                    'key' => $key,
                    'value' => $value,
                    'type' => $type,
                    'name' => $name,
                    'description' => $description,
                    'sort' => $sort,
                    'is_system' => 1,
                ]);
                continue;
            }

            // 已存在：只补齐结构信息，不动 value
            $config->group = $config->group ?: $group;
            $config->type = $config->type ?: $type;
            $config->name = $config->name ?: $name;
            $config->description = $config->description ?: $description;
            $config->is_system = 1;
            $config->save();
        }
    }
}
