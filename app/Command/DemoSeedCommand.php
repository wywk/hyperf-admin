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

namespace App\Command;

use App\Model\Department;
use App\Model\Job;
use App\Model\LoginLog;
use App\Model\Notification;
use App\Model\OperationLog;
use App\Model\Role;
use App\Model\User;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Throwable;

/**
 * 一键生成演示数据：克隆仓库后执行一次，即可看到有数据的完整界面。
 *
 * 幂等：检测到演示数据（demo_ 前缀账号）时默认跳过；--fresh 先清理再重建。
 */
#[Command]
class DemoSeedCommand extends HyperfCommand
{
    /** 演示数据标记：用户名前缀 */
    private const PREFIX = 'demo_';

    /** 批量插入的分批大小 */
    private const CHUNK = 200;

    protected ?string $signature = 'demo:seed {--fresh : 先清理已有演示数据再重新生成}';

    protected string $description = '生成演示数据（用户/部门/角色/日志/任务/通知）';

    public function handle(): int
    {
        $exists = User::query()->where('username', 'like', self::PREFIX . '%')->exists();

        if ($exists && ! $this->input->getOption('fresh')) {
            $this->output->note('检测到已有演示数据（demo_* 账号），跳过。如需重建请加 --fresh');

            return 0;
        }

        if ($exists) {
            $this->cleanup();
            $this->output->writeln('已清理旧演示数据');
        }

        Db::beginTransaction();
        try {
            $deptIds = $this->seedDepartments();
            $roleCodes = $this->seedRoles();
            $userIds = $this->seedUsers($deptIds, $roleCodes);
            $this->seedLoginLogs($userIds);
            $this->seedOperationLogs($userIds);
            $this->seedJobs($userIds);
            $this->seedNotifications($userIds);
            Db::commit();
        } catch (Throwable $e) {
            Db::rollBack();
            $this->output->error('生成失败，已回滚：' . $e->getMessage());

            return 1;
        }

        $this->output->success(sprintf(
            '演示数据就绪：%d 个部门、%d 个角色、%d 个账号、登录/操作日志各 30 天、任务与通知样例若干。前端登录 admin/admin123456 查看。',
            count($deptIds),
            count($roleCodes),
            count($userIds)
        ));

        return 0;
    }

    /**
     * 清理演示数据（--fresh）：只删 demo_ 账号及其关联记录，不动种子数据。
     */
    private function cleanup(): void
    {
        $ids = User::query()->where('username', 'like', self::PREFIX . '%')->pluck('id')->all();

        LoginLog::query()->whereIn('user_id', $ids ?: [0])->delete();
        OperationLog::query()->whereIn('user_id', $ids ?: [0])->delete();
        Notification::query()->whereIn('user_id', $ids ?: [0])->delete();
        Job::query()->whereIn('user_id', $ids ?: [0])->delete();
        User::query()->where('username', 'like', self::PREFIX . '%')->delete();

        // 演示角色（非系统内置）
        Role::query()->where('is_system', 0)->where('code', 'like', self::PREFIX . '%')->delete();

        // 演示部门（根节点带 demo 前缀）
        Department::query()->where('name', 'like', self::PREFIX . '%')->delete();
    }

    /**
     * 4 层部门树：总公司 → 技术部/运营部 → 前端组/后端组/测试组 → 若干小组。
     *
     * @return array<int, int> 部门 ID 列表
     */
    private function seedDepartments(): array
    {
        $tree = [
            'demo总公司' => [
                'demo技术部' => [
                    'demo前端组' => ['demo组件小组' => [], 'demo中台小组' => []],
                    'demo后端组' => ['demo网关小组' => [], 'demo业务小组' => []],
                    'demo测试组' => [],
                ],
                'demo运营部' => ['demo客服组' => [], 'demo内容组' => []],
                'demo人事部' => [],
            ],
        ];

        $all = [];
        $walk = function (array $nodes, int $parentId, string $parentPath) use (&$walk, &$all): void {
            $sort = 0;
            foreach ($nodes as $name => $children) {
                ++$sort;
                $dept = Department::query()->create([
                    'parent_id' => $parentId,
                    'name' => $name,
                    'code' => 'd' . substr(md5((string) $name), 0, 20),
                    'leader' => '',
                    'phone' => '',
                    'sort' => $sort,
                    'status' => 1,
                    'path' => $parentPath,
                ]);
                // 物化路径：父路径 + 自身 ID
                $dept->path = $parentPath . $dept->id . '/';
                $dept->save();
                $all[] = (int) $dept->id;

                if ($children !== []) {
                    $walk($children, (int) $dept->id, $dept->path);
                }
            }
        };

        $walk($tree, 0, '/');

        return $all;
    }

    /**
     * 3 个演示角色，覆盖数据范围的全部档位。
     *
     * @return string[] 角色编码列表
     */
    private function seedRoles(): array
    {
        $roles = [
            [self::PREFIX . 'manager', '演示-部门经理', '本部门及下级数据', 'dept_and_children'],
            [self::PREFIX . 'leader', '演示-组长', '仅本部门数据', 'dept'],
            [self::PREFIX . 'staff', '演示-员工', '仅本人数据', 'self'],
        ];

        foreach ($roles as [$code, $name, $desc, $scope]) {
            Role::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'description' => $desc,
                    'data_scope' => $scope,
                    'is_system' => 0,
                    'sort' => 90,
                ]
            );
        }

        return array_column($roles, 0);
    }

    /**
     * 50 个演示账号，均匀分布到部门和角色。
     *
     * @return array<int, int> 用户 ID 列表
     */
    private function seedUsers(array $deptIds, array $roleCodes): array
    {
        $hash = password_hash('demo123456', PASSWORD_BCRYPT);
        $nicknames = ['张伟', '王芳', '李娜', '刘洋', '陈静', '杨帆', '赵磊', '黄敏', '周杰', '吴霞',
            '徐强', '孙丽', '马超', '朱婷', '胡军', '郭靖', '林燕', '何平', '高远', '罗兰'];
        $total = 50;
        $ids = [];

        $rows = [];
        for ($i = 1; $i <= $total; ++$i) {
            $rows[] = [
                'username' => sprintf('%s%02d', self::PREFIX, $i),
                'password' => $hash,
                'nickname' => $nicknames[($i - 1) % count($nicknames)],
                'email' => sprintf('demo%02d@example.com', $i),
                'status' => $i % 25 === 0 ? 0 : 1, // 少量停用账号便于演示筛选
                'role' => $roleCodes[$i % count($roleCodes)],
                'dept_id' => $deptIds[$i % count($deptIds)],
                'created_at' => date('Y-m-d H:i:s', time() - random_int(1, 200) * 86400),
            ];
            if (count($rows) === self::CHUNK) {
                User::query()->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            User::query()->insert($rows);
        }

        $ids = User::query()->where('username', 'like', self::PREFIX . '%')->pluck('id')->all();

        return array_map('intval', $ids);
    }

    /**
     * 30 天登录日志：每天 10-25 条，覆盖全部事件类型与风险档位。
     */
    private function seedLoginLogs(array $userIds): void
    {
        $events = [LoginLog::EVENT_LOGIN_SUCCESS, LoginLog::EVENT_LOGIN_SUCCESS, LoginLog::EVENT_LOGIN_SUCCESS,
            LoginLog::EVENT_LOGIN_FAILED, LoginLog::EVENT_LOGIN_FAILED, LoginLog::EVENT_LOGOUT, LoginLog::EVENT_REFRESH_REPLAY];
        $ips = ['58.34.12.7', '112.64.68.13', '101.86.3.25', '116.226.5.90', '220.181.108.76', '39.144.10.201'];
        $uas = ['Mozilla/5.0 (Macintosh) Chrome/126', 'Mozilla/5.0 (Windows NT 10.0) Edge/126', 'Mozilla/5.0 (iPhone) Safari/17'];

        $rows = [];
        $usernames = User::query()->whereIn('id', $userIds)->pluck('username', 'id')->all();

        for ($day = 29; $day >= 0; --$day) {
            $count = random_int(10, 25);
            for ($i = 0; $i < $count; ++$i) {
                $uid = $userIds[array_rand($userIds)];
                $event = $events[array_rand($events)];
                $risk = $event === LoginLog::EVENT_LOGIN_SUCCESS ? random_int(0, 6) : 0;

                $rows[] = [
                    'user_id' => $uid,
                    'username' => $usernames[$uid] ?? '',
                    'event' => $event,
                    'ip' => $ips[array_rand($ips)],
                    'user_agent' => $uas[array_rand($uas)],
                    'message' => $event === LoginLog::EVENT_LOGIN_SUCCESS ? '登录成功' : '密码错误',
                    'risk_level' => $risk >= 5 ? 2 : ($risk >= 3 ? 1 : 0),
                    'risk_reasons' => $risk >= 5 ? '陌生网段;异常时段' : ($risk >= 3 ? '新 IP（同网段）' : ''),
                    'created_at' => date('Y-m-d H:i:s', time() - $day * 86400 - random_int(0, 82800)),
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            LoginLog::query()->insert($chunk);
        }
    }

    /**
     * 30 天操作日志：写操作流水，耗时 5-800ms，供健康监控接口耗时统计使用。
     */
    private function seedOperationLogs(array $userIds): void
    {
        $actions = [
            ['POST', '/api/products', '新建商品', 200],
            ['PUT', '/api/products/%d', '更新商品', 200],
            ['DELETE', '/api/products/%d', '删除商品', 200],
            ['POST', '/api/dicts', '新建字典', 200],
            ['POST', '/api/auth/logout', '退出登录', 200],
            ['POST', '/api/auth/login', '登录', 401],
            ['PUT', '/api/users/%d/status', '更新用户状态', 403],
        ];
        $usernames = User::query()->whereIn('id', $userIds)->pluck('username', 'id')->all();

        $rows = [];
        for ($day = 29; $day >= 0; --$day) {
            $count = random_int(15, 35);
            for ($i = 0; $i < $count; ++$i) {
                $uid = $userIds[array_rand($userIds)];
                [$method, $pathTpl, $action, $status] = $actions[array_rand($actions)];
                $path = str_replace('%d', (string) random_int(1, 99), $pathTpl);
                $failed = $status !== 200;

                $rows[] = [
                    'user_id' => $uid,
                    'username' => $usernames[$uid] ?? '',
                    'method' => $method,
                    'path' => $path,
                    'action' => $action,
                    'params' => '{}',
                    'status_code' => $failed ? $status : 200,
                    'cost_ms' => random_int(5, 800),
                    'ip' => long2ip(random_int(ip2long('58.0.0.0'), ip2long('58.34.255.254'))),
                    'user_agent' => 'demo-seed',
                    'created_at' => date('Y-m-d H:i:s', time() - $day * 86400 - random_int(0, 82800)),
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            OperationLog::query()->insert($chunk);
        }
    }

    /**
     * 任务中心样例：已完成/失败/排队中各若干，含结果文件引用说明。
     */
    private function seedJobs(array $userIds): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        $specs = [
            ['export.login_logs', Job::STATUS_SUCCEEDED, 2, '导出登录审计'],
            ['export.operation_logs', Job::STATUS_SUCCEEDED, 3, '导出操作日志'],
            ['export.products', Job::STATUS_FAILED, 1, '导出商品'],
            ['export.login_logs', Job::STATUS_WAITING, 0, '导出登录审计'],
            ['export.operation_logs', Job::STATUS_FAILED, 2, '导出操作日志'],
        ];

        foreach ($specs as $i => [$type, $status, $attempts, $label]) {
            $uid = $userIds[$i % count($userIds)];
            $finished = $status === Job::STATUS_WAITING ? null : date('Y-m-d H:i:s', strtotime($now) - random_int(600, 86400));

            $rows[] = [
                'type' => $type,
                'status' => $status,
                'payload' => json_encode(['filters' => []], JSON_UNESCAPED_UNICODE),
                'attempts' => $attempts,
                'error' => $status === Job::STATUS_FAILED ? sprintf('%s 失败：模拟演示错误（Excel 写入超时）', $label) : '',
                'result_file_id' => $status === Job::STATUS_SUCCEEDED ? null : null,
                'user_id' => $uid,
                'duration_ms' => $finished !== null ? random_int(120, 2400) : null,
                'created_at' => $finished !== null ? date('Y-m-d H:i:s', strtotime($finished) - random_int(30, 300)) : $now,
                'finished_at' => $finished,
            ];
        }

        Job::query()->insert($rows);
    }

    /**
     * 站内通知样例：给前 10 个演示账号各发 1-3 条。
     */
    private function seedNotifications(array $userIds): void
    {
        $types = [
            [Notification::TYPE_SYSTEM, Notification::LEVEL_INFO, '欢迎使用演示数据', '这是 demo:seed 生成的示例通知。'],
            [Notification::TYPE_SECURITY, Notification::LEVEL_WARNING, '检测到新设备登录', '您的账号在陌生网段登录，如非本人操作请及时修改密码。'],
            [Notification::TYPE_SYSTEM, Notification::LEVEL_ERROR, '定时任务执行失败', '数据归档任务连续失败 3 次，请查看任务详情。'],
        ];

        $rows = [];
        foreach (array_slice($userIds, 0, 10) as $i => $uid) {
            $count = ($i % 3) + 1;
            for ($j = 0; $j < $count; ++$j) {
                [$type, $level, $title, $content] = $types[($i + $j) % count($types)];
                $rows[] = [
                    'user_id' => $uid,
                    'type' => $type,
                    'level' => $level,
                    'title' => $title,
                    'content' => $content,
                    'link' => $type === Notification::TYPE_SECURITY ? '/profile' : '/system/jobs',
                    'is_read' => ($i + $j) % 4 === 0 ? 1 : 0,
                    'read_at' => null,
                ];
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            Notification::query()->insert($chunk);
        }
    }
}
