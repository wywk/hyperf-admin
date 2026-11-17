<script setup lang="ts">
/**
 * MallOrderItem 管理页（由代码生成器生成，可按需调整）。
 * 列表 / 搜索 / 新增 / 编辑 / 删除，权限码 mall_order_item.view/.add/.edit/.delete。
 */
import { onMounted, reactive, ref } from 'vue'
import {
  createMallOrderItem,
  deleteMallOrderItem,
  fetchMallOrderItemList,
  updateMallOrderItem,
} from '@/api/gen/mall_order_item'
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
    const result = await fetchMallOrderItemList({ ...query })
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
  order_id: [{ required: true, message: '请填写订单ID', trigger: 'blur' }],
  goods_name: [{ required: true, message: '请填写商品名称', trigger: 'blur' }],
  price: [{ required: true, message: '请填写单价(元)', trigger: 'blur' }],
  quantity: [{ required: true, message: '请填写数量', trigger: 'blur' }],
  amount: [{ required: true, message: '请填写小计(元)', trigger: 'blur' }],
}
const emptyForm: Record<string, any> = {
  order_id: null,
  goods_id: null,
  goods_name: '',
  price: null,
  quantity: null,
  amount: null,
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
      await updateMallOrderItem(editingId.value, { ...form })
      ElMessage.success('已保存')
    } else {
      await createMallOrderItem({ ...form })
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
    await deleteMallOrderItem(row.id)
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
      <h2 class="page-title">订单明细管理</h2>
      <el-button v-permission="'mall_order_item.add'" type="primary" @click="openCreate">新增</el-button>
    </div>

    <el-card shadow="never">
      <el-form inline class="filters" @submit.prevent>
        <el-form-item label="订单ID" prop="order_id">
          <el-input-number v-model="query.order_id" style="width: 100%" />
        </el-form-item>
        <el-form-item label="商品名称" prop="goods_name">
          <el-input v-model="query.goods_name" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="query.page = 1; load()">查询</el-button>
          <el-button @click="resetQuery()">重置</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="items" row-key="id">
        <el-table-column prop="id" label="ID" width="80" />
    <el-table-column prop="order_id" label="订单ID" min-width="130" show-overflow-tooltip />
    <el-table-column prop="goods_id" label="商品ID" min-width="130" show-overflow-tooltip />
    <el-table-column prop="goods_name" label="商品名称" min-width="130" show-overflow-tooltip />
    <el-table-column prop="price" label="单价(元)" min-width="130" show-overflow-tooltip />
    <el-table-column prop="quantity" label="数量" min-width="130" show-overflow-tooltip />
    <el-table-column prop="amount" label="小计(元)" min-width="130" show-overflow-tooltip />
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'mall_order_item.edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'mall_order_item.delete'" link type="danger" @click="remove(row)">删除</el-button>
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
        <el-form-item label="订单ID" prop="order_id">
          <el-input-number v-model="form.order_id" style="width: 100%" />
        </el-form-item>
        <el-form-item label="商品ID" prop="goods_id">
          <el-input-number v-model="form.goods_id" style="width: 100%" />
        </el-form-item>
        <el-form-item label="商品名称" prop="goods_name">
          <el-input v-model="form.goods_name" clearable />
        </el-form-item>
        <el-form-item label="单价(元)" prop="price">
          <el-input-number v-model="form.price" style="width: 100%" />
        </el-form-item>
        <el-form-item label="数量" prop="quantity">
          <el-input-number v-model="form.quantity" style="width: 100%" />
        </el-form-item>
        <el-form-item label="小计(元)" prop="amount">
          <el-input-number v-model="form.amount" style="width: 100%" />
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
