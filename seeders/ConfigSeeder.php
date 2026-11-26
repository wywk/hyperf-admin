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
        ['security', 'login_captcha_enabled', '0', Config::TYPE_BOOLEAN, '强制登录验证码', '开启后每次登录都要输入图形验证码（关闭时失败达到阈值仍会要求）', 50],
        ['security', 'login_captcha_threshold', '3', Config::TYPE_NUMBER, '验证码触发阈值', '同一 IP + 用户名连续失败多少次后要求验证码，默认 3', 60],
        ['security', 'password_min_length', '8', Config::TYPE_NUMBER, '密码最小长度', '新建账号、重置密码、自助改密都会校验，最小 6', 70],
        ['security', 'password_require_complexity', '1', Config::TYPE_BOOLEAN, '密码复杂度校验', '要求密码同时包含字母和数字，且不能包含用户名', 80],
        ['security', 'password_expire_days', '0', Config::TYPE_NUMBER, '密码有效期（天）', '0 表示不过期；到期后登录不拦截，但个人中心会提示修改', 90],
        ['security', 'risk_enabled', '0', Config::TYPE_BOOLEAN, '登录风控', '登录成功时评估风险（新 IP / 陌生网段 / 异常时段），中高风险推安全提醒；默认关闭，可在配置中心开启', 100],
        ['security', 'risk_odd_hours', '23-6', Config::TYPE_STRING, '异常时段', '格式「起-止」小时（含首含尾，可跨零点），该时段登录会提升风险等级；留空关闭时段规则', 110],

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
