<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import type { FormInstance, FormRules } from 'element-plus'
import { Plus, Refresh, Search } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { createPermission, deletePermission, fetchPermissionTree, updatePermission } from '@/api/rbac'
import type { PermissionNode } from '@/types'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const loading = ref(false)
const tree = ref<PermissionNode[]>([])
const isFlat = ref(false)

const keyword = ref('')
/** 搜索结果（平铺）；清空关键字后回到树 */
const flatItems = computed<PermissionNode[]>(() => (isFlat.value ? (tree.value as unknown as PermissionNode[]) : []))

const expandAll = ref(true)
const tableKey = ref(0)

/* ---------------- 新增 / 编辑对话框 ---------------- */

const dialogVisible = ref(false)
const submitting = ref(false)
const editing = ref<PermissionNode | null>(null)
const formRef = ref<FormInstance>()

const form = reactive({
  parent_id: 0,
  type: 'button' as 'menu' | 'button',
  code: '',
  name: '',
  path: '',
  icon: '',
  visible: 1,
  sort: 0,
  description: '',
})

/** 内置节点的权限码不可改，否则已授权角色的权限会瞬间失效 */
const codeLocked = computed(() => editing.value?.is_system === 1)
/** 创建后类型不可改，否则子节点结构、路由字段全部失去意义 */
const typeLocked = computed(() => editing.value !== null)

/** 上级候选：只允许挂到菜单节点下；根（仅菜单）用 0 表示 */
const parentOptions = computed(() => {
  const options: Array<{ id: number; label: string; disabled: boolean }> = [
    { id: 0, label: '作为一级菜单', disabled: form.type === 'button' },
  ]
  const walk = (nodes: PermissionNode[], depth: number): void => {
    for (const node of nodes) {
      if (node.type !== 'menu') {
        continue
      }
      const isSelf = editing.value !== null && node.id === editing.value.id
      options.push({
        id: node.id,
        label: `${'　'.repeat(depth)}${depth > 0 ? '└ ' : ''}${node.name}`,
        disabled: isSelf,
      })
      walk(node.children ?? [], depth + 1)
    }
  }
  walk(tree.value, 0)

  return options
})

const ICON_CHOICES = ['Odometer', 'Setting', 'Key', 'User', 'UserFilled', 'Tickets', 'Menu', 'Document', 'Lock']

const rules = computed<FormRules>(() => ({
  code: [
    { required: true, message: '请填写权限码', trigger: 'blur' },
    {
      pattern: /^[a-z][a-z0-9_.]*$/,
      message: '只能包含小写字母、数字、下划线和点，且以字母开头',
      trigger: 'blur',
    },
    { max: 64, message: '最长 64 个字符', trigger: 'blur' },
  ],
  name: [
    { required: true, message: '请填写名称', trigger: 'blur' },
    { max: 64, message: '最长 64 个字符', trigger: 'blur' },
  ],
  description: [{ max: 255, message: '最长 255 个字符', trigger: 'blur' }],
}))

/* ---------------- 数据加载 ---------------- */

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchPermissionTree(keyword.value.trim() || undefined)
    tree.value = result.items
    isFlat.value = result.flat
    tableKey.value += 1
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

function search(): void {
  void load()
}

function resetSearch(): void {
  keyword.value = ''
  void load()
}

function toggleExpand(): void {
  expandAll.value = !expandAll.value
  tableKey.value += 1
}

/* ---------------- 表单操作 ---------------- */

function openCreate(parent?: PermissionNode): void {
  editing.value = null
  form.parent_id = parent?.id ?? 0
  form.type = parent ? 'button' : 'menu'
  form.code = ''
  form.name = ''
  form.path = ''
  form.icon = ''
  form.visible = 1
  form.sort = ((parent?.children?.length ? parent.children[parent.children.length - 1].sort : parent?.sort) ?? 0) + 10
  form.description = ''
  dialogVisible.value = true
}

function openEdit(row: PermissionNode): void {
  editing.value = row
  form.parent_id = row.parent_id
  form.type = row.type
  form.code = row.code
  form.name = row.name
  form.path = row.path
  form.icon = row.icon
  form.visible = row.visible
  form.sort = row.sort
  form.description = row.description
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
      parent_id: form.parent_id,
      code: form.code.trim(),
      name: form.name.trim(),
      path: form.type === 'menu' ? form.path.trim() : undefined,
      icon: form.type === 'menu' ? form.icon : undefined,
      visible: form.type === 'menu' ? form.visible : undefined,
      sort: form.sort,
      description: form.description.trim(),
    }

    if (editing.value) {
      await updatePermission(editing.value.id, payload)
      ElMessage.success('已保存')
    } else {
      await createPermission({ ...payload, type: form.type })
      ElMessage.success('已创建')
    }

    dialogVisible.value = false
    await load()
    // 菜单/权限码可能变化，当前账号的菜单树要跟着刷新
    void auth.fetchProfile(true)
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    submitting.value = false
  }
}

async function remove(row: PermissionNode): Promise<void> {
  try {
    await ElMessageBox.confirm(
      '删除后该权限会从所有角色上移除，且不可恢复。确定删除「' + row.name + '」吗？',
      '删除权限',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await deletePermission(row.id)
    ElMessage.success('已删除')
    await load()
    void auth.fetchProfile(true)
  } catch {
    // 有子节点等情况，错误信息由请求层弹出
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
        <h2>权限管理</h2>
        <p class="sub">
          权限是一棵树：<el-tag size="small" effect="plain">菜单</el-tag> 决定页面入口与侧边栏，
          <el-tag size="small" type="success" effect="plain">按钮</el-tag> 挂在菜单下决定页面内操作。
          内置节点不可删除、不可改权限码；新增后默认授予管理员。
        </p>
      </div>
      <div class="header-actions">
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
        <el-button v-permission="'permission.add'" type="primary" @click="openCreate()">
          <el-icon><Plus /></el-icon>
          <span>新增权限</span>
        </el-button>
      </div>
    </div>

    <el-card shadow="never" class="filter-card">
      <el-form inline @submit.prevent="search">
        <el-form-item label="关键字">
          <el-input
            v-model="keyword"
            placeholder="权限码 / 名称"
            clearable
            style="width: 220px"
            @keyup.enter="search"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">
            <el-icon><Search /></el-icon>
            <span>查询</span>
          </el-button>
          <el-button v-if="isFlat" @click="resetSearch">回到树形</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table
        v-if="!isFlat"
        :key="tableKey"
        v-loading="loading"
        :data="tree"
        row-key="id"
        :tree-props="{ children: 'children' }"
        :default-expand-all="expandAll"
        stripe
        style="width: 100%"
      >
        <el-table-column label="名称" min-width="260">
          <template #default="{ row }">
            <el-tag :type="row.type === 'menu' ? 'primary' : 'success'" size="small" effect="plain" class="type-tag">
              {{ row.type === 'menu' ? '菜单' : '按钮' }}
            </el-tag>
            <span class="name">{{ row.name }}</span>
            <el-tag v-if="row.is_system" type="warning" size="small" effect="light">内置</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="权限码" min-width="180">
          <template #default="{ row }">
            <span class="mono code">{{ row.code }}</span>
          </template>
        </el-table-column>
        <el-table-column label="路由" min-width="160">
          <template #default="{ row }">
            <span v-if="row.path" class="mono path">/{{ row.path }}</span>
            <span v-else class="text-muted">—</span>
          </template>
        </el-table-column>
        <el-table-column label="已分配角色" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="row.role_count ? 'success' : 'info'" effect="light" size="small">
              {{ row.role_count ?? 0 }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" align="center" />
        <el-table-column label="操作" width="190" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.type === 'menu'"
              v-permission="'permission.add'"
              link
              type="primary"
              @click="openCreate(row)"
            >
              加子级
            </el-button>
            <el-button v-permission="'permission.edit'" link type="primary" @click="openEdit(row)">
              编辑
            </el-button>
            <el-tooltip v-if="row.is_system" content="内置权限不可删除" placement="top">
              <span class="disabled-action">删除</span>
            </el-tooltip>
            <el-button
              v-else-if="auth.hasPermission('permission.delete')"
              link
              type="danger"
              @click="remove(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="还没有任何权限" :image-size="80" />
        </template>
      </el-table>

      <el-table v-else v-loading="loading" :data="flatItems" stripe style="width: 100%">
        <el-table-column label="名称" min-width="200">
          <template #default="{ row }">
            <el-tag :type="row.type === 'menu' ? 'primary' : 'success'" size="small" effect="plain" class="type-tag">
              {{ row.type === 'menu' ? '菜单' : '按钮' }}
            </el-tag>
            <span class="name">{{ row.name }}</span>
          </template>
        </el-table-column>
        <el-table-column label="权限码" min-width="180">
          <template #default="{ row }">
            <span class="mono code">{{ row.code }}</span>
          </template>
        </el-table-column>
        <el-table-column label="路由" min-width="150">
          <template #default="{ row }">
            <span v-if="row.path" class="mono path">/{{ row.path }}</span>
            <span v-else class="text-muted">—</span>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" align="center" />
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'permission.edit'" link type="primary" @click="openEdit(row)">
              编辑
            </el-button>
            <el-tooltip v-if="row.is_system" content="内置权限不可删除" placement="top">
              <span class="disabled-action">删除</span>
            </el-tooltip>
            <el-button v-else link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="没有匹配的权限" :image-size="80" />
        </template>
      </el-table>

      <div v-if="!isFlat" class="tree-toolbar">
        <el-button link type="primary" @click="toggleExpand">
          {{ expandAll ? '全部折叠' : '全部展开' }}
        </el-button>
      </div>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="editing ? '编辑权限' : '新增权限'"
      width="540px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="类型">
          <el-radio-group v-model="form.type" :disabled="typeLocked">
            <el-radio-button value="menu">菜单</el-radio-button>
            <el-radio-button value="button">按钮</el-radio-button>
          </el-radio-group>
          <p class="field-tip">
            <template v-if="typeLocked">类型创建后不可更改（按钮 → 编辑再建新节点）。</template>
            <template v-else>菜单 = 页面入口；按钮 = 菜单页内的操作（增删改查等）。</template>
          </p>
        </el-form-item>
        <el-form-item label="上级">
          <el-select v-model="form.parent_id" style="width: 100%">
            <el-option
              v-for="option in parentOptions"
              :key="option.id"
              :label="option.label"
              :value="option.id"
              :disabled="option.disabled"
            />
          </el-select>
          <p v-if="form.type === 'button'" class="field-tip">按钮必须挂在菜单下。</p>
        </el-form-item>
        <el-form-item label="权限码" prop="code">
          <el-input v-model="form.code" :disabled="codeLocked" placeholder="例如 report.export" class="mono-input" />
          <p class="field-tip">
            <template v-if="codeLocked">内置权限的权限码不可修改。</template>
            <template v-else>
              菜单码用于路由守卫（meta.permission），按钮码用于 v-permission 与后端 authorize。
            </template>
          </p>
        </el-form-item>
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="例如 导出报表" maxlength="64" show-word-limit />
        </el-form-item>
        <template v-if="form.type === 'menu'">
          <el-form-item label="路由路径">
            <el-input v-model="form.path" placeholder="例如 system/permissions（留空 = 纯目录）" class="mono-input" />
            <p class="field-tip">目录节点可以留空路由，仅用于把菜单分组。</p>
          </el-form-item>
          <el-form-item label="图标">
            <el-select v-model="form.icon" placeholder="选择图标" clearable style="width: 100%">
              <el-option v-for="name in ICON_CHOICES" :key="name" :label="name" :value="name" />
            </el-select>
          </el-form-item>
          <el-form-item label="侧边栏显示">
            <el-switch v-model="form.visible" :active-value="1" :inactive-value="0" />
          </el-form-item>
        </template>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="99999" :step="10" controls-position="right" />
        </el-form-item>
        <el-form-item label="说明">
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="2"
            maxlength="255"
            show-word-limit
            placeholder="这条权限管什么，写清楚方便后来人"
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
  </div>
</template>

<style scoped>
.header-actions {
  display: flex;
  gap: 10px;
}

.filter-card,
.table-card {
  border-radius: var(--app-radius);
}

.filter-card :deep(.el-form-item) {
  margin-bottom: 0;
}

.type-tag {
  margin-right: 6px;
}

.name {
  margin-right: 8px;
  font-weight: 500;
}

.code {
  font-size: 12.5px;
  color: #1d4ed8;
}

.path {
  font-size: 12.5px;
  color: var(--app-muted);
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

.tree-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}
</style>
