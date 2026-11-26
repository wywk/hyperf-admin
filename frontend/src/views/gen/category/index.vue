<script setup lang="ts">
/**
 * Category 管理页（由代码生成器生成，可按需调整）。
 * 列表 / 搜索 / 新增 / 编辑 / 删除，权限码 category.view/.add/.edit/.delete。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import {
  batchDeleteCategory,
  createCategory,
  deleteCategory,
  exportCategoryExcel,
  fetchCategoryList,
  importCategoryExcel,
  updateCategory,
} from '@/api/gen/category'
import { ElMessage, ElMessageBox } from '@/plugins/element'


const loading = ref(false)
const exporting = ref(false)
const selectedIds = ref<number[]>([])
const statusLabels: Record<string, string> = {
  '1': '启用',
  '0': '停用',
}

const items = ref<any[]>([])
const total = ref(0)
const query = reactive<Record<string, any>>({
  page: 1,
  page_size: 10,
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchCategoryList({ ...query })
    items.value = buildTree(result.items)
    total.value = result.total
  } catch {
    // 错误由请求层统一提示
  } finally {
    loading.value = false
  }
}

function resetQuery(): void {
  for (const key of Object.keys(query)) {
    if (key !== 'page' && key !== 'page_size') {
      delete query[key]
    }
  }
  query.page = 1
  void load()
}

/** 按当前搜索条件导出全量 Excel */
async function exportExcel(): Promise<void> {
  exporting.value = true
  try {
    const filters: Record<string, unknown> = {}
    for (const key of Object.keys(query)) {
      if (key !== 'page' && key !== 'page_size') {
        filters[key] = query[key]
      }
    }
    const blob = await exportCategoryExcel(filters)
    downloadBlob(blob, 'category.xlsx')
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string): void {
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename
  link.click()
  URL.revokeObjectURL(link.href)
}

onMounted(load)

/* ---------------- 表单 ---------------- */

const dialogVisible = ref(false)
const submitting = ref(false)
const editingId = ref(0)
const formRef = ref()
const form = reactive<Record<string, any>>({})
const rules: Record<string, unknown> = {
  name: [{ required: true, message: '请填写分类名称', trigger: 'blur' }],
}
const emptyForm: Record<string, any> = {
  name: '',
  sort: null,
  status: '',
  parent_id: null,
}

function openCreate(parentId?: number): void {
  editingId.value = 0
  resetForm()
  Object.assign(form, JSON.parse(JSON.stringify(emptyForm)))
  if (parentId) {
    form.parent_id = parentId
  }
  dialogVisible.value = true
}

function openEdit(row: any): void {
  editingId.value = row.id
  resetForm()
  Object.assign(form, JSON.parse(JSON.stringify(emptyForm)))
  Object.assign(form, JSON.parse(JSON.stringify(row)))
  delete form.id
  dialogVisible.value = true
}

function resetForm(): void {
  for (const key of Object.keys(form)) {
    delete form[key]
  }
}

async function submit(): Promise<void> {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  submitting.value = true
  try {
    if (editingId.value > 0) {
      await updateCategory(editingId.value, { ...form })
      ElMessage.success('已保存')
    } else {
      await createCategory({ ...form })
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    await load()
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    submitting.value = false
  }
}

async function remove(row: any): Promise<void> {
  try {
    await ElMessageBox.confirm('确定删除这条记录吗？', '删除', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  try {
    await deleteCategory(row.id)
    ElMessage.success('已删除')
    await load()
  } catch {
    // 错误提示由请求层统一处理
  }
}

function onSelectionChange(rows: any[]): void {
  selectedIds.value = rows.map((row) => row.id)
}

async function batchRemove(): Promise<void> {
  if (selectedIds.value.length === 0) {
    return
  }
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${selectedIds.value.length} 条记录吗？`, '批量删除', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  try {
    const result = await batchDeleteCategory(selectedIds.value)
    ElMessage.success(`已删除 ${result.deleted} 条`)
    selectedIds.value = []
    await load()
  } catch {
    // 错误提示由请求层统一处理
  }
}

/* ---------------- 导入 Excel ---------------- */

const importing = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const importResult = reactive<{ visible: boolean; success: number; failed: Array<{ row: number; errors: string[] }> }>({
  visible: false,
  success: 0,
  failed: [],
})

function triggerImport(): void {
  fileInput.value?.click()
}

async function onImportFile(e: Event): Promise<void> {
  const target = e.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (!file) {
    return
  }

  importing.value = true
  try {
    const result = await importCategoryExcel(file)
    importResult.success = result.success
    importResult.failed = result.failed
    importResult.visible = true
    await load()
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    importing.value = false
  }
}
/* ---------------- 详情抽屉 ---------------- */

const detailVisible = ref(false)
const detailRow = ref<Record<string, any>>({})

function openDetail(row: any): void {
  detailRow.value = row
  detailVisible.value = true
}
/* ---------------- 树形构建 ---------------- */

function buildTree(rows: any[]): any[] {
  const nodes = new Map<number, any>()
  for (const row of rows) {
    nodes.set(Number(row.id), { ...row, children: [] })
  }
  const roots: any[] = []
  for (const node of nodes.values()) {
    const parent = node.parent_id ? nodes.get(Number(node.parent_id)) : null
    if (parent) {
      parent.children.push(node)
    } else {
      roots.push(node)
    }
  }
  return roots
}

const parentTreeOptions = computed(() => {
  const clone = (nodes: any[], excludeId: number): any[] =>
    nodes
      .filter((node) => node.id !== excludeId)
      .map((node) => ({
        value: node.id,
        label: node.name ?? `#${node.id}`,
        children: clone(node.children ?? [], excludeId),
      }))
  return clone(items.value, editingId.value)
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h2 class="page-title">商品分类管理</h2>
      <div class="header-actions">
        <el-button :loading="exporting" @click="exportExcel">导出 Excel</el-button>
        <el-button v-permission="'category.add'" :loading="importing" @click="triggerImport">导入 Excel</el-button>
        <input ref="fileInput" type="file" accept=".xlsx" class="hidden-import-input" @change="onImportFile" />
        <el-button v-permission="'category.delete'" :disabled="selectedIds.length === 0" type="danger" plain @click="batchRemove">
          批量删除{{ selectedIds.length > 0 ? `(${selectedIds.length})` : '' }}
        </el-button>
        <el-button v-permission="'category.add'" type="primary" @click="openCreate">新增</el-button>
      </div>
    </div>

    <el-card shadow="never">
      <el-form inline class="filters" @submit.prevent>
        <el-form-item label="分类名称" prop="name">
          <el-input v-model="query.name" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="query.status" clearable filterable style="width: 180px">
              <el-option key="1" label="启用" value="1" />
              <el-option key="0" label="停用" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="query.page = 1; load()">查询</el-button>
          <el-button @click="resetQuery()">重置</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="items" row-key="id" :tree-props="{ children: 'children' }" default-expand-all @selection-change="onSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="80" />
    <el-table-column prop="name" label="分类名称" min-width="130" show-overflow-tooltip />
    <el-table-column prop="sort" label="排序" min-width="130" show-overflow-tooltip />
    <el-table-column prop="status" label="状态" width="90">
      <template #default="{ row }">
        <el-tag type="info">{{ statusLabels[String(row.status)] ?? row.status }}</el-tag>
      </template>
    </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'category.view'" link @click="openDetail(row)">详情</el-button>
            <el-button v-permission="'category.add'" link type="primary" @click="openCreate(row.id)">子级</el-button>
            <el-button v-permission="'category.edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'category.delete'" link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无数据" :image-size="80" />
        </template>
      </el-table>

    </el-card>

    <el-dialog v-model="dialogVisible" :title="editingId > 0 ? '编辑' : '新增'" width="560px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item label="上级" prop="parent_id">
          <el-tree-select
            v-model="form.parent_id"
            :data="parentTreeOptions"
            check-strictly
            clearable
            placeholder="不选则为顶级"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="分类名称" prop="name">
          <el-input v-model="form.name" clearable />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="form.sort" style="width: 100%" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="form.status" clearable filterable>
              <el-option key="1" label="启用" value="1" />
              <el-option key="0" label="停用" value="0" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">保存</el-button>
      </template>
    </el-dialog>

    <el-drawer v-model="detailVisible" title="详情" size="420px">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="ID">{{ detailRow.id ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="分类名称">{{ detailRow.name ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="排序">{{ detailRow.sort ?? '-' }}</el-descriptions-item>
        <el-descriptions-item label="状态">{{ statusLabels[String(detailRow.status)] ?? detailRow.status ?? '-' }}</el-descriptions-item>
      </el-descriptions>
    </el-drawer>
    <el-dialog v-model="importResult.visible" title="导入结果" width="520px">
      <p>
        共处理 {{ importResult.success + importResult.failed.length }} 行：
        <el-tag type="success">成功 {{ importResult.success }} 行</el-tag>
        <el-tag v-if="importResult.failed.length > 0" type="danger" style="margin-left: 8px">
          失败 {{ importResult.failed.length }} 行
        </el-tag>
      </p>
      <el-table v-if="importResult.failed.length > 0" :data="importResult.failed" size="small" max-height="280">
        <el-table-column prop="row" label="行号（含表头）" width="140" />
        <el-table-column label="失败原因">
          <template #default="{ row }">
            {{ row.errors.join('；') }}
          </template>
        </el-table-column>
      </el-table>
      <template #footer>
        <el-button type="primary" @click="importResult.visible = false">知道了</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}

.page-title {
  margin: 0;
  font-size: 20px;
}

.header-actions {
  display: flex;
  gap: 8px;
}

.hidden-import-input {
  display: none;
}

.filters {
  margin-bottom: 4px;
}

.pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
</style>
