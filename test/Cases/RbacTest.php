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

use App\Service\RbacService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Testing\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RbacTest extends TestCase
{
    private RbacService $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rbac = ApplicationContext::getContainer()->get(RbacService::class);
    }

    public function testPermissionTreeHasMenuAndButton(): void
    {
        $result = $this->rbac->permissionTree();
        $this->assertNotEmpty($result['items']);
        $this->assertFalse($result['flat']);

        $root = $result['items'][0];
        $this->assertSame('menu', $root['type']);
        $this->assertArrayHasKey('children', $root);
    }

    public function testAdminRoleHasAllSeededPermissions(): void
    {
        $codes = $this->rbac->permissionsForRole('admin');
        $this->assertNotEmpty($codes);
        $this->assertContains('role.view', $codes);
        $this->assertContains('gen.create', $codes);
    }

    public function testUserRoleIsSubsetOfAdmin(): void
    {
        $admin = $this->rbac->permissionsForRole('admin');
        $user = $this->rbac->permissionsForRole('user');

        $this->assertNotEmpty($user);
        $this->assertNotSame([], array_diff($admin, $user), 'user 角色权限应少于 admin');
    }

    public function testSyncRolePermissionsRoundTrip(): void
    {
        $roleId = $this->findRoleIdByCode('user');
        $original = $this->rbac->rolePermissionIds($roleId);
        $this->assertNotEmpty($original, 'user 角色种子数据应已有授权');

        // 只授予 role.view → 生效并可回读
        $roleViewId = $this->findIdByCode($this->rbac->permissionTree()['items'], 'role.view');
        $this->assertNotNull($roleViewId, '权限树里应存在 role.view');

        $applied = $this->rbac->syncRolePermissions($roleId, [$roleViewId]);
        $this->assertSame([$roleViewId], $applied);
        $this->assertSame([$roleViewId], $this->rbac->rolePermissionIds($roleId));

        // 同步缓存读取也反映新授权
        $this->assertSame(['role.view'], array_values($this->rbac->permissionsForRole('user')));

        // 恢复种子授权
        $this->rbac->syncRolePermissions($roleId, $original);
        $this->assertSame(
            array_map('intval', $original),
            array_map('intval', $this->rbac->rolePermissionIds($roleId)),
        );
    }

    public function testRoleListIncludesSystemRoles(): void
    {
        $result = $this->rbac->listRoles();
        $roles = $result['items'];
        $this->assertSame($result['total'], count($roles));

        $codes = array_column($roles, 'code');
        $this->assertContains('admin', $codes);
        $this->assertContains('user', $codes);

        $admin = $roles[array_search('admin', $codes, true)];
        $this->assertSame(1, (int) $admin['is_system']);
    }

    private function findIdByCode(array $nodes, string $code): ?int
    {
        foreach ($nodes as $node) {
            if (($node['code'] ?? null) === $code) {
                return (int) $node['id'];
            }
            $found = $this->findIdByCode($node['children'] ?? [], $code);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function findRoleIdByCode(string $code): int
    {
        foreach ($this->rbac->listRoles()['items'] as $role) {
            if ($role['code'] === $code) {
                return (int) $role['id'];
            }
        }
        $this->fail("角色 {$code} 不存在");
    }
}
