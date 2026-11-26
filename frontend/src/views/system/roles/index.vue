<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import type { ElTree, FormInstance, FormRules } from 'element-plus'
import { Key, Plus, Refresh } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import {
  createRole,
  deleteRole,
  fetchPermissionTree,
  fetchRolePermissions,
  fetchRoles,
  syncRolePermissions,
  updateRole,
} from '@/api/rbac'
import type { DataScope, PermissionNode, RoleItem } from '@/types'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const loading = ref(false)
const roles = ref<RoleItem[]>([])

const dialogVisible = ref(false)
const submitting = ref(false)
const editing = ref<RoleItem | null>(null)
const formRef = ref<FormInstance>()
const form = reactive({ code: '', name: '', description: '', sort: 0, data_scope: 'all' as DataScope })

const SCOPE_OPTIONS: Array<{ value: DataScope; label: string; desc: string }> = [
  { value: 'all', label: '全部数据', desc: '不受部门限制，能看到所有数据' },
  { value: 'dept', label: '本部门', desc: '只看同部门的数据' },
  { value: 'dept_and_children', label: '本部门及下级', desc: '本部门 + 所有下级部门' },
  { value: 'self', label: '仅本人', desc: '只能看到自己的数据' },
]

const SCOPE_TEXT: Record<DataScope, string> = {
  all: '全部数据',
  dept: '本部门',
  dept_and_children: '本部门及下级',
  self: '仅本人',
}

function scopeText(scope?: DataScope): string {
  return scope ? SCOPE_TEXT[scope] ?? scope : '全部数据'
}

function scopeTagType(scope?: DataScope): 'success' | 'warning' | 'info' | 'primary' {
  if (scope === 'self') return 'info'
  if (scope === 'dept' || scope === 'dept_and_children') return 'warning'
  return 'success'
}

const codeLocked = computed(() => editing.value?.is_system === 1)

const rules: FormRules = {
  code: [
    { required: true, message: '请填写角色标识', trigger: 'blur' },
    {
      pattern: /^[a-z][a-z0-9_-]*$/,
      message: '只能包含小写字母、数字、下划线和短横线，且以字母开头',
      trigger: 'blur',
    },
    { max: 32, message: '最长 32 个字符', trigger: 'blur' },
  ],
  name: [
    { required: true, message: '请填写角色名称', trigger: 'blur' },
    { max: 64, message: '最长 64 个字符', trigger: 'blur' },
  ],
  description: [{ max: 255, message: '最长 255 个字符', trigger: 'blur' }],
}

/* ---------------- 列表 ---------------- */

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchRoles()
    roles.value = result.items
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

function openCreate(): void {
  editing.value = null
  form.code = ''
  form.name = ''
  form.description = ''
  form.sort = (roles.value[roles.value.length - 1]?.sort ?? 0) + 10
  form.data_scope = 'all'
  dialogVisible.value = true
}

function openEdit(row: RoleItem): void {
  editing.value = row
  form.code = row.code
  form.name = row.name
  form.description = row.description
  form.sort = row.sort
  form.data_scope = row.data_scope ?? 'all'
  dialogVisible.value = true
}

async function submit(): Promise<void> {
  if (!formRef.value) {
    return
  }

  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) {
    return
  }

  submitting.value = true
  try {
    const payload = {
      code: form.code.trim(),
      name: form.name.trim(),
      description: form.description.trim(),
      sort: form.sort,
      data_scope: form.data_scope,
    }

    if (editing.value) {
      await updateRole(editing.value.id, payload)
      ElMessage.success('已保存')
    } else {
      await createRole(payload)
      ElMessage.success('已创建')
    }

    dialogVisible.value = false
    await load()
    void auth.fetchProfile(true)
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    submitting.value = false
  }
}

async function remove(row: RoleItem): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `删除后该角色的授权关系一并清除。确定删除「${row.name}」吗？`,
      '删除角色',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await deleteRole(row.id)
    ElMessage.success('已删除')
    await load()
  } catch {
    // 角色下还有用户等情况，错误信息由请求层弹出
  }
}

/* ---------------- 授权（权限树勾选） ---------------- */

const authDialogVisible = ref(false)
const authLoading = ref(false)
const authSubmitting = ref(false)
const authRole = ref<RoleItem | null>(null)
const permTree = ref<PermissionNode[]>([])
const treeRef = ref<InstanceType<typeof ElTree>>()
let grantedIds: number[] = []

interface TreeNode {
  id: number
  label: string
  type: 'menu' | 'button'
  code: string
  isSystem: boolean
  isLeaf: boolean
  children?: TreeNode[]
}

const treeData = computed<TreeNode[]>(() => {
  const walk = (nodes: PermissionNode[]): TreeNode[] =>
    nodes.map((node) => ({
      id: node.id,
      label: node.name,
      type: node.type,
      code: node.code,
      isSystem: node.is_system === 1,
      isLeaf: (node.children?.length ?? 0) === 0,
      children: walk(node.children ?? []),
    }))

  return walk(permTree.value)
})

const treeProps = {
  label: 'label',
  children: 'children',
  disabled: (node: TreeNode): boolean =>
    // 系统角色的内置权限不允许取消，否则可能把管理入口全部关掉
    authRole.value?.is_system === 1 && node.isSystem,
}

/** el-tree 会把勾选联动到父级，回显只能勾叶子，父级交给半选状态推导 */
function leafIdsAmongGranted(nodes: TreeNode[], granted: Set<number>): number[] {
  const ids: number[] = []
  for (const node of nodes) {
    if (node.children?.length) {
      ids.push(...leafIdsAmongGranted(node.children, granted))
    } else if (granted.has(node.id)) {
      ids.push(node.id)
    }
  }

  return ids
}

async function openAuth(row: RoleItem): Promise<void> {
  authRole.value = row
  authDialogVisible.value = true
  authLoading.value = true
  grantedIds = []

  try {
    const [tree, granted] = await Promise.all([fetchPermissionTree(), fetchRolePermissions(row.id)])
    permTree.value = tree.items
    grantedIds = granted.permission_ids
    await nextTick()
    applyChecked()
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    authLoading.value = false
  }
}

function applyChecked(): void {
  const grantedSet = new Set(grantedIds)
  treeRef.value?.setCheckedKeys(leafIdsAmongGranted(treeData.value, grantedSet), false)
}

function onAuthOpened(): void {
  applyChecked()
}

async function saveAuth(): Promise<void> {
  if (!authRole.value || !treeRef.value) {
    return
  }

  // 提交勾选 + 半选的节点：半选的父级菜单是子按钮的载体，必须一并入库
  const checked = (treeRef.value.getCheckedKeys(false) as number[]) ?? []
  const halfChecked = (treeRef.value.getHalfCheckedKeys() as number[]) ?? []
  const permissionIds = Array.from(new Set([...checked, ...halfChecked])).filter((id) => Number.isFinite(id))

  authSubmitting.value = true
  try {
    const result = await syncRolePermissions(authRole.value.id, permissionIds)
    ElMessage.success('授权已保存')

    // 系统角色会被后端补齐内置权限，以后端返回的最终结果为准
    grantedIds = result.permission_ids
    applyChecked()

    authDialogVisible.value = false
    await load()

    if (auth.user?.role === authRole.value.code) {
      await auth.fetchProfile(true)
      ElMessage.info('当前账号的权限已更新，菜单与按钮立即生效')
    }
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    authSubmitting.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>角色管理</h2>
        <p class="sub">
          用户的 <code class="mono">users.role</code> 存的是角色标识；给角色勾选权限后，该角色下的用户下次登录即生效。
        </p>
      </div>
      <div class="header-actions">
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
        <el-button v-permission="'role.add'" type="primary" @click="openCreate">
          <el-icon><Plus /></el-icon>
          <span>新增角色</span>
        </el-button>
      </div>
    </div>

    <el-card shadow="never" class="table-card">
      <el-table v-loading="loading" :data="roles" stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="角色标识" width="160">
          <template #default="{ row }">
            <span class="mono code">{{ row.code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="name" label="角色名称" min-width="130" />
        <el-table-column label="说明" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <span :class="row.description ? '' : 'text-muted'">{{ row.description || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="数据范围" width="120">
          <template #default="{ row }">
            <el-tag :type="scopeTagType(row.data_scope)" effect="plain" size="small">
              {{ scopeText(row.data_scope) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="用户数" width="90" align="center">
          <template #default="{ row }">
            <el-tag type="info" effect="plain" size="small">{{ row.user_count ?? 0 }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="权限数" width="90" align="center">
          <template #default="{ row }">
            <el-tag type="success" effect="light" size="small">{{ row.permission_count ?? 0 }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="90">
          <template #default="{ row }">
            <el-tag v-if="row.is_system" type="warning" effect="light" size="small">内置</el-tag>
            <span v-else class="text-muted">自定义</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="190" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'role.assign'" link type="primary" @click="openAuth(row)">
              分配权限
            </el-button>
            <el-button v-permission="'role.edit'" link type="primary" @click="openEdit(row)">
              编辑
            </el-button>
            <el-tooltip v-if="row.is_system" content="内置角色不可删除" placement="top">
              <span class="disabled-action">删除</span>
            </el-tooltip>
            <el-button
              v-else-if="auth.hasPermission('role.delete')"
              link
              type="danger"
              @click="remove(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无角色" :image-size="80" />
        </template>
      </el-table>
    </el-card>

    <!-- 新增 / 编辑角色 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editing ? '编辑角色' : '新增角色'"
      width="520px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="角色标识" prop="code">
          <el-input v-model="form.code" :disabled="codeLocked" placeholder="例如 auditor" class="mono-input" />
          <p class="field-tip">
            <template v-if="codeLocked">内置角色的标识不可修改。</template>
            <template v-else>
              改标识会同步更新已分配该角色的用户，不会让他们掉权限。
            </template>
          </p>
        </el-form-item>
        <el-form-item label="角色名称" prop="name">
          <el-input v-model="form.name" placeholder="例如 审计员" maxlength="64" show-word-limit />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" :min="0" :max="99999" :step="10" controls-position="right" />
        </el-form-item>
        <el-form-item label="数据范围">
          <el-select v-model="form.data_scope" class="w-full">
            <el-option
              v-for="option in SCOPE_OPTIONS"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            >
              <div class="scope-option">
                <span>{{ option.label }}</span>
                <small>{{ option.desc }}</small>
              </div>
            </el-option>
          </el-select>
        </el-form-item>
        <el-form-item label="说明" prop="description">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="2"
            maxlength="255"
            show-word-limit
            placeholder="这个角色负责什么"
          />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">
          {{ editing ? '保存' : '创建' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 分配权限 -->
    <el-dialog
      v-model="authDialogVisible"
      :title="authRole ? `分配权限 · ${authRole.name}` : '分配权限'"
      width="640px"
      :close-on-click-modal="false"
      @opened="onAuthOpened"
    >
      <el-alert
        v-if="authRole?.is_system"
        type="warning"
        :closable="false"
        show-icon
        title="内置角色的内置权限会始终保留，取消勾选也不会生效。"
        class="auth-alert"
      />

      <div v-loading="authLoading" class="tree-wrap">
        <el-tree
          v-if="treeData.length > 0"
          ref="treeRef"
          :data="treeData"
          :props="treeProps"
          node-key="id"
          show-checkbox
          default-expand-all
          empty-text="还没有任何权限，先去权限管理里加一条"
        >
          <template #default="{ data }">
            <span class="tree-node">
              <el-tag :type="data.type === 'menu' ? 'primary' : 'success'" size="small" effect="plain">
                {{ data.type === 'menu' ? '菜单' : '按钮' }}
              </el-tag>
              <span class="tree-name">{{ data.label }}</span>
              <span class="tree-code mono">{{ data.code }}</span>
            </span>
          </template>
        </el-tree>
        <el-empty v-else-if="!authLoading" description="还没有任何权限" :image-size="70" />
      </div>

      <template #footer>
        <span class="auth-foot-tip">
          <el-icon><Key /></el-icon>
          勾选菜单会带上其下全部按钮；只勾部分按钮时，上级菜单自动保留（半选）
        </span>
        <el-button @click="authDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="authSubmitting" @click="saveAuth">保存授权</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.header-actions {
  display: flex;
  gap: 10px;
}

.table-card {
  border-radius: var(--app-radius);
}

.code {
  font-size: 12.5px;
  color: #1d4ed8;
}

.disabled-action {
  color: #c0c4cc;
  font-size: 13px;
  cursor: not-allowed;
  padding: 0 4px;
}

.field-tip {
  margin: 4px 0 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--app-muted);
}

.mono-input :deep(input) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.auth-alert {
  margin-bottom: 12px;
}

.tree-wrap {
  max-height: 52vh;
  overflow-y: auto;
  padding: 4px 2px;
  min-height: 120px;
}

.auth-foot-tip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  margin-right: auto;
  font-size: 12px;
  color: var(--app-muted);
}

.tree-node {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}

.tree-name {
  font-weight: 500;
}

.tree-code {
  font-size: 11.5px;
  color: var(--app-muted);
}

/* dialog footer 是 flex 布局，让提示靠左 */
:deep(.el-dialog__footer) {
  display: flex;
  align-items: center;
  gap: 8px;
}
.w-full {
  width: 100%;
}

.scope-option {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.scope-option small {
  color: var(--app-muted);
  font-size: 11.5px;
}
</style>
