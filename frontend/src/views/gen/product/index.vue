<script setup lang="ts">
/**
 * Product 管理页（由代码生成器生成，可按需调整）。
 * 列表 / 搜索 / 新增 / 编辑 / 删除，权限码 product.view/.add/.edit/.delete。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import {
  batchDeleteProduct,
  createProduct,
  deleteProduct,
  exportProductExcel,
  fetchProductList,
  importProductExcel,
  updateProduct,
} from '@/api/gen/product'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { fetchDict } from '@/api/dict'

const loading = ref(false)
const exporting = ref(false)
const selectedIds = ref<number[]>([])
const statusOptions = ref<Array<{ label: string; value: string }>>([])

const statusLabels = computed<Record<string, string>>(() => Object.fromEntries(statusOptions.value.map((o) => [String(o.value), o.label])))

const items = ref<any[]>([])
const total = ref(0)
const query = reactive<Record<string, any>>({
  page: 1,
  page_size: 10,
})

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchProductList({ ...query })
    items.value = result.items
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
    const blob = await exportProductExcel(filters)
    downloadBlob(blob, 'product.xlsx')
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

onMounted(async () => {
  statusOptions.value = (await fetchDict('common_status')).items
  await load()
})

/* ---------------- 表单 ---------------- */

const dialogVisible = ref(false)
const submitting = ref(false)
const editingId = ref(0)
const formRef = ref()
const form = reactive<Record<string, any>>({})
const rules: Record<string, unknown> = {
  name: [{ required: true, message: '请填写名称', trigger: 'blur' }],
  price: [{ required: true, message: '请填写价格', trigger: 'blur' }],
}
const emptyForm: Record<string, any> = {
  name: '',
  price: null,
  stock: null,
  status: '',
}

function openCreate(): void {
  editingId.value = 0
  resetForm()
  Object.assign(form, JSON.parse(JSON.stringify(emptyForm)))
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
      await updateProduct(editingId.value, { ...form })
      ElMessage.success('已保存')
    } else {
      await createProduct({ ...form })
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
    await deleteProduct(row.id)
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
    const result = await batchDeleteProduct(selectedIds.value)
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
    const result = await importProductExcel(file)
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
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h2 class="page-title">示例商品管理</h2>
      <div class="header-actions">
        <el-button :loading="exporting" @click="exportExcel">导出 Excel</el-button>
        <el-button v-permission="'product.add'" :loading="importing" @click="triggerImport">导入 Excel</el-button>
        <input ref="fileInput" type="file" accept=".xlsx" class="hidden-import-input" @change="onImportFile" />
        <el-button v-permission="'product.delete'" :disabled="selectedIds.length === 0" type="danger" plain @click="batchRemove">
          批量删除{{ selectedIds.length > 0 ? `(${selectedIds.length})` : '' }}
        </el-button>
        <el-button v-permission="'product.add'" type="primary" @click="openCreate">新增</el-button>
      </div>
    </div>

    <el-card shadow="never">
      <el-form inline class="filters" @submit.prevent>
        <el-form-item label="名称" prop="name">
          <el-input v-model="query.name" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="价格" prop="price">
          <el-input-number v-model="query.price" style="width: 100%" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="query.status" clearable filterable style="width: 180px">
              <el-option v-for="opt in statusOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="query.page = 1; load()">查询</el-button>
          <el-button @click="resetQuery()">重置</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="items" row-key="id" @selection-change="onSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="id" label="ID" width="80" />
    <el-table-column prop="name" label="名称" min-width="130" show-overflow-tooltip />
    <el-table-column prop="price" label="价格" min-width="130" show-overflow-tooltip />
    <el-table-column prop="stock" label="库存" min-width="130" show-overflow-tooltip />
    <el-table-column prop="status" label="状态" width="90">
      <template #default="{ row }">
        <el-tag type="info">{{ statusLabels[String(row.status)] ?? row.status }}</el-tag>
      </template>
    </el-table-column>
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'product.edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'product.delete'" link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无数据" :image-size="80" />
        </template>
      </el-table>

      <div class="pagination">
        <el-pagination
          v-model:current-page="query.page"
          :total="total"
          :page-size="query.page_size"
          layout="total, prev, pager, next"
          background
          @current-change="load"
        />
      </div>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="editingId > 0 ? '编辑' : '新增'" width="560px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" clearable />
        </el-form-item>
        <el-form-item label="价格" prop="price">
          <el-input-number v-model="form.price" style="width: 100%" />
        </el-form-item>
        <el-form-item label="库存" prop="stock">
          <el-input-number v-model="form.stock" style="width: 100%" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="form.status" clearable filterable>
              <el-option v-for="opt in statusOptions" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">保存</el-button>
      </template>
    </el-dialog>

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
