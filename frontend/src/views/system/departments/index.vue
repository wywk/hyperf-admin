<script setup lang="ts">
/**
 * 部门管理：树形表格 + 弹窗维护。
 *
 * 部门树是数据权限的基础：角色的「本部门 / 本部门及下级」都按这棵树算。
 * 移动部门到自己的下级会被后端拒绝（否则树会成环）。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { Plus, Refresh } from '@element-plus/icons-vue'
import type { FormInstance, FormRules } from 'element-plus'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { createDepartment, deleteDepartment, fetchDepartments, updateDepartment } from '@/api/departments'
import { useAuthStore } from '@/stores/auth'
import type { DepartmentNode } from '@/types'

const auth = useAuthStore()

const loading = ref(false)
const tree = ref<DepartmentNode[]>([])

async function load(): Promise<void> {
  loading.value = true
  try {
    tree.value = await fetchDepartments()
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

/** el-tree-select 需要 {value,label,children} 结构 */
interface SelectNode {
  value: number
  label: string
  children: SelectNode[]
}

function toSelect(nodes: DepartmentNode[]): SelectNode[] {
  return nodes.map((node) => ({
    value: node.id,
    label: node.name,
    children: toSelect(node.children ?? []),
  }))
}

const selectTree = computed(() => toSelect(tree.value))

const totalCount = computed(() => {
  const count = (nodes: DepartmentNode[]): number =>
    nodes.reduce((sum, node) => sum + 1 + count(node.children ?? []), 0)
  return count(tree.value)
})

/* ---------------- 新增 / 编辑 ---------------- */

const dialogVisible = ref(false)
const submitting = ref(false)
const editingId = ref<number | null>(null)
const formRef = ref<FormInstance>()

const form = reactive({
  parent_id: 0,
  name: '',
  code: '',
  leader: '',
  phone: '',
  sort: 0,
  status: 1,
})

const rules: FormRules = {
  name: [
    { required: true, message: '请填写部门名称', trigger: 'blur' },
    { max: 64, message: '名称最长 64 个字符', trigger: 'blur' },
  ],
  code: [
    { required: true, message: '请填写部门标识', trigger: 'blur' },
    { pattern: /^[a-z][a-z0-9_-]*$/, message: '小写字母开头，只能含字母/数字/下划线/短横线', trigger: 'blur' },
  ],
}

function openCreate(parentId = 0): void {
  editingId.value = null
  Object.assign(form, { parent_id: parentId, name: '', code: '', leader: '', phone: '', sort: 0, status: 1 })
  dialogVisible.value = true
}

function openEdit(row: DepartmentNode): void {
  editingId.value = row.id
  Object.assign(form, {
    parent_id: row.parent_id,
    name: row.name,
    code: row.code,
    leader: row.leader,
    phone: row.phone,
    sort: row.sort,
    status: row.status,
  })
  dialogVisible.value = true
}

/** 编辑时不能把自己或自己的下级选成上级 */
function disabledNode(node: SelectNode): boolean {
  if (editingId.value === null) {
    return false
  }
  if (node.value === editingId.value) {
    return true
  }
  const walk = (nodes: SelectNode[]): boolean =>
    nodes.some((item) => item.value === editingId.value || walk(item.children))
  return walk(node.children)
}

async function submit(): Promise<void> {
  if (!formRef.value) return

  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    const payload = {
      parent_id: form.parent_id,
      name: form.name.trim(),
      code: form.code.trim(),
      leader: form.leader.trim(),
      phone: form.phone.trim(),
      sort: form.sort,
      status: form.status,
    }

    if (editingId.value === null) {
      await createDepartment(payload)
      ElMessage.success('部门已创建')
    } else {
      await updateDepartment(editingId.value, payload)
      ElMessage.success('部门已保存')
    }

    dialogVisible.value = false
    await load()
  } catch {
    // 标识重复、上级非法等提示由请求层弹出
  } finally {
    submitting.value = false
  }
}

async function remove(row: DepartmentNode): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `确定删除部门「${row.name}」吗？有子部门或仍有用户时会被拒绝。`,
      '删除部门',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await deleteDepartment(row.id)
    ElMessage.success('部门已删除')
    await load()
  } catch {
    // 请求层已提示「还有子部门 / 还有用户」
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
        <h2>部门管理</h2>
        <p class="sub">
          部门树是数据权限的基础：角色可配置「全部 / 本部门 / 本部门及下级 / 仅本人」。
        </p>
      </div>
      <div class="header-actions">
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
        <el-button v-if="auth.hasPermission('dept.add')" type="primary" @click="openCreate(0)">
          <el-icon><Plus /></el-icon>
          <span>新增部门</span>
        </el-button>
      </div>
    </div>

    <el-card shadow="never" class="table-card">
      <el-table
        v-loading="loading"
        :data="tree"
        row-key="id"
        :tree-props="{ children: 'children' }"
        default-expand-all
        style="width: 100%"
      >
        <el-table-column label="部门名称" min-width="220">
          <template #default="{ row }">
            <div class="name-cell">
              <span class="name">{{ row.name }}</span>
              <el-tag v-if="row.status === 0" size="small" type="info" effect="plain">已停用</el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="标识" width="150">
          <template #default="{ row }">
            <span class="mono">{{ row.code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="leader" label="负责人" width="130">
          <template #default="{ row }">{{ row.leader || '—' }}</template>
        </el-table-column>
        <el-table-column prop="phone" label="联系电话" width="150">
          <template #default="{ row }">{{ row.phone || '—' }}</template>
        </el-table-column>
        <el-table-column label="用户数" width="100" align="center">
          <template #default="{ row }">
            <el-tag size="small" effect="plain" :type="row.user_count > 0 ? 'primary' : 'info'">
              {{ row.user_count ?? 0 }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="80" align="center" />
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button v-if="auth.hasPermission('dept.add')" link type="primary" @click="openCreate(row.id)">
              加下级
            </el-button>
            <el-button v-if="auth.hasPermission('dept.edit')" link type="primary" @click="openEdit(row)">
              编辑
            </el-button>
            <el-button v-if="auth.hasPermission('dept.delete')" link type="danger" @click="remove(row)">
              删除
            </el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="还没有部门，先建一个吧" :image-size="80" />
        </template>
      </el-table>

      <div class="foot">共 {{ totalCount }} 个部门</div>
    </el-card>

    <el-dialog
      v-model="dialogVisible"
      :title="editingId === null ? '新增部门' : '编辑部门'"
      width="480px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="上级部门">
          <el-tree-select
            v-model="form.parent_id"
            :data="selectTree"
            :props="{ value: 'value', label: 'label', children: 'children', disabled: disabledNode }"
            check-strictly
            default-expand-all
            placeholder="不选则为顶级部门"
            clearable
            class="w-full"
          />
        </el-form-item>
        <el-form-item label="部门名称" prop="name">
          <el-input v-model="form.name" placeholder="如：技术部" />
        </el-form-item>
        <el-form-item label="部门标识" prop="code">
          <el-input v-model="form.code" placeholder="如：tech，全站唯一" />
        </el-form-item>
        <el-form-item label="负责人">
          <el-input v-model="form.leader" placeholder="选填" />
        </el-form-item>
        <el-form-item label="联系电话">
          <el-input v-model="form.phone" placeholder="选填" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="99999" controls-position="right" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}

.page-header h2 {
  margin: 0 0 6px;
  font-size: 20px;
}

.sub {
  margin: 0;
  font-size: 12.5px;
  color: var(--app-muted);
}

.header-actions {
  display: flex;
  gap: 10px;
  flex-shrink: 0;
}

.table-card {
  border-radius: var(--app-radius);
}

.name-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.name {
  font-size: 13.5px;
  font-weight: 500;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
  color: var(--app-muted);
}

.foot {
  padding-top: 12px;
  font-size: 12.5px;
  color: var(--app-muted);
  text-align: right;
}

.w-full {
  width: 100%;
}
</style>
