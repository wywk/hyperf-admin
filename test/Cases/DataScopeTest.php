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

namespace HyperfTest\Cases;

use App\Exception\BusinessException;
use App\Model\Department;
use App\Model\Role;
use App\Model\User;
use App\Service\DataScopeService;
use App\Service\DepartmentService;
use App\Service\RbacService;
use App\Service\UserService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DataScopeTest extends TestCase
{
    private const PREFIX = 'test_scope_';

    private DataScopeService $scope;

    private DepartmentService $departments;

    private UserService $users;

    private int $deptA;

    private int $deptB;

    private int $deptC;

    protected function setUp(): void
    {
        parent::setUp();
        $container = ApplicationContext::getContainer();
        $this->scope = $container->get(DataScopeService::class);
        $this->departments = $container->get(DepartmentService::class);
        $this->users = $container->get(UserService::class);

        $this->cleanup();

        $a = $this->departments->create(['name' => 'A 公司', 'code' => self::PREFIX . 'a']);
        $b = $this->departments->create([
            'parent_id' => $a['id'],
            'name' => 'A 的技术部',
            'code' => self::PREFIX . 'b',
        ]);
        $c = $this->departments->create(['name' => 'C 公司', 'code' => self::PREFIX . 'c']);

        $this->deptA = (int) $a['id'];
        $this->deptB = (int) $b['id'];
        $this->deptC = (int) $c['id'];

        // 三个专用角色，各配一种数据范围
        $rbac = $container->get(RbacService::class);
        $rbac->createRole(['code' => self::PREFIX . 'dept', 'name' => '部门管理员', 'data_scope' => 'dept']);
        $rbac->createRole(['code' => self::PREFIX . 'children', 'name' => '部门及下级管理员', 'data_scope' => 'dept_and_children']);
        $rbac->createRole(['code' => self::PREFIX . 'self', 'name' => '仅本人', 'data_scope' => 'self']);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    public function testDeptScopeSeesOnlySameDepartment(): void
    {
        $mgr = $this->makeUser('mgr_dept', self::PREFIX . 'dept', $this->deptB);
        $peer = $this->makeUser('peer_b', 'user', $this->deptB);
        $outsider = $this->makeUser('outsider_c', 'user', $this->deptC);

        $ids = $this->scope->visibleUserIds($mgr);
        $this->assertNotNull($ids);
        $this->assertContains((int) $mgr->id, $ids);
        $this->assertContains((int) $peer->id, $ids);
        $this->assertNotContains((int) $outsider->id, $ids);

        // 列表也被过滤
        $page = $this->users->paginate(['keyword' => self::PREFIX], 1, 50, $mgr);
        $this->assertSame(2, $page['total']);
    }

    public function testDeptAndChildrenScopeIncludesSubDepartments(): void
    {
        $mgr = $this->makeUser('mgr_children', self::PREFIX . 'children', $this->deptA);
        $inChild = $this->makeUser('in_child', 'user', $this->deptB);
        $inRoot = $this->makeUser('in_root', 'user', $this->deptA);
        $outsider = $this->makeUser('out_c', 'user', $this->deptC);

        $ids = $this->scope->visibleUserIds($mgr);
        $this->assertNotNull($ids);
        $this->assertContains((int) $inRoot->id, $ids);
        $this->assertContains((int) $inChild->id, $ids);
        $this->assertNotContains((int) $outsider->id, $ids);
    }

    public function testSelfScopeOnlySeesSelf(): void
    {
        $u = $this->makeUser('solo', self::PREFIX . 'self', $this->deptC);
        $this->makeUser('solo_peer', 'user', $this->deptC);

        $this->assertSame([(int) $u->id], $this->scope->visibleUserIds($u));
    }

    public function testDeptScopeWithoutDepartmentFallsBackToSelf(): void
    {
        $u = $this->makeUser('no_dept', self::PREFIX . 'dept', null);

        $this->assertSame([(int) $u->id], $this->scope->visibleUserIds($u));
    }

    public function testAdminScopeIsUnrestricted(): void
    {
        /** @var User $admin */
        $admin = User::query()->where('username', 'admin')->first();
        $this->assertNull($this->scope->visibleUserIds($admin));
    }

    public function testCannotManageUserOutsideScope(): void
    {
        $mgr = $this->makeUser('mgr_guard', self::PREFIX . 'dept', $this->deptB);
        $peer = $this->makeUser('peer_guard', 'user', $this->deptB);
        $outsider = $this->makeUser('out_guard', 'user', $this->deptC);

        // 部门内可以停用
        $this->users->updateStatus((int) $peer->id, 0, (int) $mgr->id);
        $this->assertSame(0, (int) User::query()->where('id', $peer->id)->value('status'));

        // 部门外不行
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/数据权限/');
        $this->users->updateStatus((int) $outsider->id, 0, (int) $mgr->id);
    }

    private function makeUser(string $suffix, string $role, ?int $deptId): User
    {
        $created = $this->users->create([
            'username' => self::PREFIX . $suffix,
            'password' => 'Scope123456',
            'role' => $role,
            'dept_id' => $deptId,
        ]);

        /* @var User $user */
        return User::query()->find((int) $created['id']);
    }

    private function cleanup(): void
    {
        User::query()->where('username', 'like', self::PREFIX . '%')->delete();
        Role::query()->where('code', 'like', self::PREFIX . '%')->delete();

        /** @var array<int, Department> $rows */
        $rows = Department::query()->where('code', 'like', self::PREFIX . '%')->get()->all();
        usort($rows, static fn (Department $a, Department $b): int => strlen((string) $b->path) <=> strlen((string) $a->path));
        foreach ($rows as $row) {
            $row->delete();
        }
    }
}
