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

namespace App\Service;

use App\Common\ApiCode;
use App\Exception\BusinessException;
use App\Model\Department;
use App\Model\User;
use Hyperf\DbConnection\Db;

/**
 * 部门管理：树的增删改查 + 物化路径维护。
 *
 * 路径规则：顶级部门 '/1/'，其子部门 '/1/3/'。这样「查某部门及下级」就是
 * path LIKE '/1/3/%'，不用递归；移动部门时只重写子树前缀。
 */
class DepartmentService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function tree(): array
    {
        /** @var array<int, Department> $rows */
        $rows = Department::query()->orderBy('sort')->orderBy('id')->get()->all();

        $counts = Db::table('users')
            ->selectRaw('dept_id, count(*) as aggregate')
            ->whereNotNull('dept_id')
            ->groupBy('dept_id')
            ->pluck('aggregate', 'dept_id')
            ->all();

        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) $row->parent_id][] = $row;
        }

        // 行已按 sort,id 排好，递归时天然保持顺序；depth 兜底防数据成环
        $build = function (int $parentId, int $depth = 0) use (&$build, $byParent, $counts): array {
            if ($depth > 50) {
                return [];
            }

            $list = [];
            foreach ($byParent[$parentId] ?? [] as $row) {
                $list[] = $this->format($row) + [
                    'user_count' => (int) ($counts[(int) $row->id] ?? 0),
                    'children' => $build((int) $row->id, $depth + 1),
                ];
            }

            return $list;
        };

        return $build(0);
    }

    /**
     * @param array{parent_id?: int, name: string, code: string, leader?: string, phone?: string, sort?: int, status?: int} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        if (Department::query()->where('code', $data['code'])->exists()) {
            throw new BusinessException(ApiCode::VALIDATION_FAILED, '该部门标识已存在');
        }

        $parentId = (int) ($data['parent_id'] ?? 0);
        $parent = $parentId > 0 ? $this->findOrFail($parentId) : null;

        $department = Department::query()->create([
            'parent_id' => $parentId,
            'name' => $data['name'],
            'code' => $data['code'],
            'leader' => (string) ($data['leader'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'sort' => (int) ($data['sort'] ?? 0),
            'status' => (int) ($data['status'] ?? Department::STATUS_ENABLED) === 0 ? 0 : 1,
            'path' => '',
        ]);

        $department->path = ($parent?->path ?: '/') . $department->id . '/';
        $department->save();

        return $this->format($department) + ['children' => []];
    }

    /**
     * @param array{parent_id?: int, name?: string, code?: string, leader?: string, phone?: string, sort?: int, status?: int} $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $department = $this->findOrFail($id);

        if (isset($data['code']) && $data['code'] !== (string) $department->code) {
            if (Department::query()->where('code', $data['code'])->where('id', '<>', $id)->exists()) {
                throw new BusinessException(ApiCode::VALIDATION_FAILED, '该部门标识已存在');
            }
            $department->code = $data['code'];
        }

        if (isset($data['name']) && $data['name'] !== '') {
            $department->name = $data['name'];
        }

        if (isset($data['parent_id']) && (int) $data['parent_id'] !== (int) $department->parent_id) {
            $parentId = (int) $data['parent_id'];
            if ($parentId === $id) {
                throw new BusinessException(ApiCode::FAILED, '上级部门不能是自己');
            }

            $parent = $parentId > 0 ? $this->findOrFail($parentId) : null;
            if ($parent !== null && str_contains((string) $parent->path, '/' . $id . '/')) {
                throw new BusinessException(ApiCode::FAILED, '上级部门不能选自己的下级');
            }

            $department->parent_id = $parentId;
        }

        foreach (['leader', 'phone'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                $department->{$field} = (string) $data[$field];
            }
        }
        if (isset($data['sort'])) {
            $department->sort = (int) $data['sort'];
        }
        if (isset($data['status'])) {
            $department->status = (int) $data['status'] === 0 ? 0 : 1;
        }

        $oldPath = (string) $department->path;
        $department->save();

        // 重新算自己的路径；如果动了层级，子树路径要整体换前缀
        $parentPath = '/';
        if ((int) $department->parent_id > 0) {
            $parentPath = (string) Department::query()->where('id', (int) $department->parent_id)->value('path');
            $parentPath = $parentPath !== '' ? $parentPath : '/';
        }
        $newPath = $parentPath . $department->id . '/';

        if ($newPath !== $oldPath) {
            $department->path = $newPath;
            $department->save();

            if ($oldPath !== '' && $oldPath !== '/') {
                /** @var array<int, Department> $descendants */
                $descendants = Department::query()
                    ->where('path', 'like', $oldPath . '%')
                    ->where('id', '<>', $department->id)
                    ->get()
                    ->all();
                foreach ($descendants as $child) {
                    $child->path = str_replace($oldPath, $newPath, (string) $child->path);
                    $child->save();
                }
            }
        }

        return $this->format($department) + ['children' => []];
    }

    public function delete(int $id): void
    {
        $department = $this->findOrFail($id);

        if (Department::query()->where('parent_id', $id)->exists()) {
            throw new BusinessException(ApiCode::FAILED, '该部门下还有子部门，请先删除或移走子部门');
        }

        if (User::query()->where('dept_id', $id)->exists()) {
            throw new BusinessException(ApiCode::FAILED, '该部门下还有用户，请先调整用户所属部门');
        }

        $department->delete();
    }

    /**
     * 某部门及其全部下级的 ID（数据权限「本部门及下级」用）。
     *
     * @return array<int, int>
     */
    public function descendantAndSelfIds(?int $deptId): array
    {
        if ($deptId === null || $deptId <= 0) {
            return [];
        }

        $path = (string) Department::query()->where('id', $deptId)->value('path');
        if ($path === '') {
            return [$deptId];
        }

        /** @var array<int, int> $ids */
        $ids = Department::query()->where('path', 'like', $path . '%')->pluck('id')->all();

        return $ids === [] ? [$deptId] : array_map('intval', $ids);
    }

    /**
     * @return array<string, mixed>
     */
    public function format(Department $department): array
    {
        return [
            'id' => (int) $department->id,
            'parent_id' => (int) $department->parent_id,
            'name' => (string) $department->name,
            'code' => (string) $department->code,
            'leader' => (string) $department->leader,
            'phone' => (string) $department->phone,
            'sort' => (int) $department->sort,
            'status' => (int) $department->status,
            'path' => (string) $department->path,
            'created_at' => $department->created_at?->toDateTimeString(),
        ];
    }

    private function findOrFail(int $id): Department
    {
        /** @var null|Department $department */
        $department = Department::query()->find($id);
        if ($department === null) {
            throw new BusinessException(ApiCode::NOT_FOUND, '部门不存在或已被删除');
        }

        return $department;
    }
}
