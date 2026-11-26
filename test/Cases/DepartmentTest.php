<?php

declare(strict_types=1);
/**
 * 部门树测试：路径维护、移动子树、删除守卫。
 *
 * @internal
 * @coversNothing
 */

namespace HyperfTest\Cases;

use App\Exception\BusinessException;
use App\Model\Department;
use App\Model\User;
use App\Service\DepartmentService;
use App\Service\UserService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

class DepartmentTest extends TestCase
{
    private const PREFIX = 'test_dept_';

    private DepartmentService $departments;

    private UserService $users;

    protected function setUp(): void
    {
        parent::setUp();
        $container = ApplicationContext::getContainer();
        $this->departments = $container->get(DepartmentService::class);
        $this->users = $container->get(UserService::class);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testCreateTreeAndPath(): void
    {
        $root = $this->departments->create(['name' => '根部门', 'code' => self::PREFIX . 'root']);
        $child = $this->departments->create([
            'parent_id' => $root['id'],
            'name' => '子部门',
            'code' => self::PREFIX . 'child',
        ]);
        $grand = $this->departments->create([
            'parent_id' => $child['id'],
            'name' => '孙部门',
            'code' => self::PREFIX . 'grand',
        ]);

        $this->assertSame('/' . $root['id'] . '/', $root['path']);
        $this->assertSame('/' . $root['id'] . '/' . $child['id'] . '/', $child['path']);

        $tree = $this->departments->tree();
        $rootNode = $this->findNode($tree, (int) $root['id']);
        $this->assertNotNull($rootNode);
        $childNode = $this->findNode($rootNode['children'], (int) $child['id']);
        $this->assertNotNull($childNode);
        $this->assertNotNull($this->findNode($childNode['children'], (int) $grand['id']));

        // 本部门及下级
        $ids = $this->departments->descendantAndSelfIds((int) $root['id']);
        sort($ids);
        $expected = [(int) $root['id'], (int) $child['id'], (int) $grand['id']];
        sort($expected);
        $this->assertSame($expected, $ids);
    }

    public function testCannotMoveUnderSelfOrDescendant(): void
    {
        $root = $this->departments->create(['name' => '移动根', 'code' => self::PREFIX . 'mv_root']);
        $child = $this->departments->create([
            'parent_id' => $root['id'],
            'name' => '移动子',
            'code' => self::PREFIX . 'mv_child',
        ]);

        try {
            $this->departments->update((int) $root['id'], ['parent_id' => (int) $root['id']]);
            $this->fail('上级不能是自己');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('自己', $e->getMessage());
        }

        $this->expectExceptionMessageMatches('/下级/');
        $this->departments->update((int) $root['id'], ['parent_id' => (int) $child['id']]);
    }

    public function testMoveDepartmentRewritesSubtreePath(): void
    {
        $a = $this->departments->create(['name' => 'A', 'code' => self::PREFIX . 'a']);
        $b = $this->departments->create(['name' => 'B', 'code' => self::PREFIX . 'b']);
        $child = $this->departments->create([
            'parent_id' => $a['id'],
            'name' => 'A 的子',
            'code' => self::PREFIX . 'a_child',
        ]);
        $grand = $this->departments->create([
            'parent_id' => $child['id'],
            'name' => 'A 的孙',
            'code' => self::PREFIX . 'a_grand',
        ]);

        // 把 A 的子部门整体挂到 B 下面
        $this->departments->update((int) $child['id'], ['parent_id' => (int) $b['id']]);

        /** @var Department $childRow */
        $childRow = Department::query()->find((int) $child['id']);
        /** @var Department $grandRow */
        $grandRow = Department::query()->find((int) $grand['id']);

        $this->assertSame('/' . $b['id'] . '/' . $child['id'] . '/', (string) $childRow->path);
        $this->assertSame('/' . $b['id'] . '/' . $child['id'] . '/' . $grand['id'] . '/', (string) $grandRow->path);
    }

    public function testDeleteGuards(): void
    {
        $root = $this->departments->create(['name' => '删除根', 'code' => self::PREFIX . 'del_root']);
        $this->departments->create([
            'parent_id' => $root['id'],
            'name' => '删除子',
            'code' => self::PREFIX . 'del_child',
        ]);

        try {
            $this->departments->delete((int) $root['id']);
            $this->fail('有子部门时不应允许删除');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('子部门', $e->getMessage());
        }

        $leaf = $this->departments->create(['name' => '有人的部门', 'code' => self::PREFIX . 'del_user']);
        $this->users->create([
            'username' => self::PREFIX . 'member',
            'password' => 'Dept123456',
            'role' => 'user',
            'dept_id' => (int) $leaf['id'],
        ]);

        $this->expectExceptionMessageMatches('/用户/');
        $this->departments->delete((int) $leaf['id']);
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     * @return null|array<string, mixed>
     */
    private function findNode(array $nodes, int $id): ?array
    {
        foreach ($nodes as $node) {
            if ((int) $node['id'] === $id) {
                return $node;
            }
            $found = $this->findNode($node['children'], $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function cleanup(): void
    {
        /** @var array<int, int> $userIds */
        $userIds = User::query()->where('username', 'like', self::PREFIX . '%')->pluck('id')->all();
        if ($userIds !== []) {
            User::query()->whereIn('id', $userIds)->delete();
        }

        // 需要按层级从深到浅删，直接按 path 长度倒序最省事
        /** @var array<int, Department> $rows */
        $rows = Department::query()->where('code', 'like', self::PREFIX . '%')->get()->all();
        usort($rows, static fn (Department $a, Department $b): int => strlen((string) $b->path) <=> strlen((string) $a->path));
        foreach ($rows as $row) {
            $row->delete();
        }
    }
}
