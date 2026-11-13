<script setup lang="ts">
/**
 * Product 管理页（由代码生成器生成，可按需调整）。
 * 列表 / 搜索 / 新增 / 编辑 / 删除，权限码 product.view/.add/.edit/.delete。
 */
import { onMounted, reactive, ref } from 'vue'
import {
  createProduct,
  deleteProduct,
  fetchProductList,
  updateProduct,
} from '@/api/gen/product'
import { ElMessage, ElMessageBox } from '@/plugins/element'

const loading = ref(false)
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

onMounted(load)

/* ---------------- 表单 ---------------- */

const dialogVisible = ref(false)
const submitting = ref(false)
const editingId = ref(0)
const formRef = ref()
const form = reactive<Record<string, any>>({})
const rules: Record<string, unknown> = {
  name: [{ required: true, message: '请填写商品名称', trigger: 'blur' }],
  category: [{ required: true, message: '请填写分类', trigger: 'change' }],
  price: [{ required: true, message: '请填写价格', trigger: 'blur' }],
}
const emptyForm: Record<string, any> = {
  id: null,
  name: '',
  category: '',
  price: null,
  stock: null,
  status: false,
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
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h2 class="page-title">示例商品管理</h2>
      <el-button v-permission="'product.add'" type="primary" @click="openCreate">新增</el-button>
    </div>

    <el-card shadow="never">
      <el-form inline class="filters" @submit.prevent>
        <el-form-item label="商品名称" prop="name">
          <el-input v-model="query.name" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="分类" prop="category">
          <el-select v-model="query.category" clearable filterable style="width: 180px">
              <el-option key="数码" label="数码" value="数码" />
              <el-option key="家居" label="家居" value="家居" />
              <el-option key="食品" label="食品" value="食品" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="query.page = 1; load()">查询</el-button>
          <el-button @click="resetQuery()">重置</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="items" row-key="id">
        <el-table-column prop="id" label="ID" width="80" />
    <el-table-column prop="name" label="商品名称" min-width="130" show-overflow-tooltip />
    <el-table-column prop="category" label="分类" min-width="130" show-overflow-tooltip />
    <el-table-column prop="price" label="价格" min-width="130" show-overflow-tooltip />
    <el-table-column prop="stock" label="库存" min-width="130" show-overflow-tooltip />
    <el-table-column prop="status" label="上架" width="90">
      <template #default="{ row }">
        <el-tag :type="row.status ? 'success' : 'info'">{{ row.status ? '是' : '否' }}</el-tag>
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
        <el-form-item label="商品名称" prop="name">
          <el-input v-model="form.name" clearable />
        </el-form-item>
        <el-form-item label="分类" prop="category">
          <el-select v-model="form.category" clearable filterable>
              <el-option key="数码" label="数码" value="数码" />
              <el-option key="家居" label="家居" value="家居" />
              <el-option key="食品" label="食品" value="食品" />
          </el-select>
        </el-form-item>
        <el-form-item label="价格" prop="price">
          <el-input-number v-model="form.price" style="width: 100%" />
        </el-form-item>
        <el-form-item label="库存" prop="stock">
          <el-input-number v-model="form.stock" style="width: 100%" />
        </el-form-item>
        <el-form-item label="上架" prop="status">
          <el-switch v-model="form.status" />
        </el-form-item>
        <el-form-item label="描述" prop="description">
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
