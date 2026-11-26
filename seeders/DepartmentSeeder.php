<?php

declare(strict_types=1);
/**
 * This file is part of hyperf-admin.
 *
 * @link     https://github.com/wywk/hyperf-admin
 * @license  MIT
 */
use App\Model\Department;
use Hyperf\Database\Seeders\Seeder;

/**
 * 演示部门树：只在部门表为空时写入，避免覆盖你在后台调整过的结构。
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        if (Department::query()->exists()) {
            return;
        }

        $hq = Department::query()->create([
            'parent_id' => 0,
            'name' => '总公司',
            'code' => 'hq',
            'leader' => '总经理',
            'phone' => '',
            'sort' => 10,
            'status' => 1,
            'path' => '',
        ]);
        $hq->path = '/' . $hq->id . '/';
        $hq->save();

        $tech = Department::query()->create([
            'parent_id' => (int) $hq->id,
            'name' => '技术部',
            'code' => 'tech',
            'leader' => '技术总监',
            'phone' => '',
            'sort' => 10,
            'status' => 1,
            'path' => '',
        ]);
        $tech->path = $hq->path . $tech->id . '/';
        $tech->save();

        foreach ([['前端组', 'fe', 10], ['后端组', 'be', 20]] as [$name, $code, $sort]) {
            $child = Department::query()->create([
                'parent_id' => (int) $tech->id,
                'name' => $name,
                'code' => $code,
                'leader' => '',
                'phone' => '',
                'sort' => $sort,
                'status' => 1,
                'path' => '',
            ]);
            $child->path = $tech->path . $child->id . '/';
            $child->save();
        }

        $ops = Department::query()->create([
            'parent_id' => (int) $hq->id,
            'name' => '运营部',
            'code' => 'ops',
            'leader' => '',
            'phone' => '',
            'sort' => 20,
            'status' => 1,
            'path' => '',
        ]);
        $ops->path = $hq->path . $ops->id . '/';
        $ops->save();
    }
}
