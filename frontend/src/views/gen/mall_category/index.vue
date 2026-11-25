<script setup lang="ts">
/**
 * MallCategory 管理页（由代码生成器生成，可按需调整）。
 * 列表 / 搜索 / 新增 / 编辑 / 删除，权限码 mall_category.view/.add/.edit/.delete。
 */
import { onMounted, reactive, ref } from 'vue'
import {
  createMallCategory,
  deleteMallCategory,
  fetchMallCategoryList,
  updateMallCategory,
} from '@/api/gen/mall_category'
import { ElMessage, ElMessageBox } from '@/plugins/element'

const loading = ref(false)
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
    const result = await fetchMallCategoryList({ ...query })
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
  description: '',
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
      await updateMallCategory(editingId.value, { ...form })
      ElMessage.success('已保存')
    } else {
      await createMallCategory({ ...form })
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
    await deleteMallCategory(row.id)
    ElMessage.success('已删除')
    await load()
  } catch {
    // 错误提示由请求层统一处理
  }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h2 class="page-title">分类管理管理</h2>
      <el-button v-permission="'mall_category.add'" type="primary" @click="openCreate">新增</el-button>
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

      <el-table v-loading="loading" :data="items" row-key="id">
        <el-table-column prop="id" label="ID" width="80" />
    <el-table-column prop="name" label="分类名称" min-width="130" show-overflow-tooltip />
    <el-table-column prop="sort" label="排序" min-width="130" show-overflow-tooltip />
    <el-table-column prop="status" label="状态" width="90">
      <template #default="{ row }">
        <el-tag type="info">{{ statusLabels[String(row.status)] ?? row.status }}</el-tag>
      </template>
    </el-table-column>
    <el-table-column prop="description" label="说明" min-width="130" show-overflow-tooltip />
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'mall_category.edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'mall_category.delete'" link type="danger" @click="remove(row)">删除</el-button>
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
        <el-form-item label="说明" prop="description">
          <el-input v-model="form.description" type="textarea" :rows="3" />
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
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}

.page-title {
  margin: 0;
  font-size: 20px;
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
