<script setup lang="ts">
/**
 * MallOrder 管理页（由代码生成器生成，可按需调整）。
 * 列表 / 搜索 / 新增 / 编辑 / 删除，权限码 mall_order.view/.add/.edit/.delete。
 */
import { onMounted, reactive, ref } from 'vue'
import {
  createMallOrder,
  deleteMallOrder,
  fetchMallOrderList,
  updateMallOrder,
} from '@/api/gen/mall_order'
import { ElMessage, ElMessageBox } from '@/plugins/element'

const loading = ref(false)
const statusLabels: Record<string, string> = {
  '0': '待付款',
  '1': '已付款',
  '2': '已发货',
  '3': '已完成',
  '4': '已取消',
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
    const result = await fetchMallOrderList({ ...query })
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
  order_no: [{ required: true, message: '请填写订单号', trigger: 'blur' }],
  amount: [{ required: true, message: '请填写金额(元)', trigger: 'blur' }],
}
const emptyForm: Record<string, any> = {
  order_no: '',
  receiver: '',
  phone: '',
  amount: null,
  status: '',
  user_id: null,
  address: '',
  remark: '',
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
      await updateMallOrder(editingId.value, { ...form })
      ElMessage.success('已保存')
    } else {
      await createMallOrder({ ...form })
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
    await deleteMallOrder(row.id)
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
      <h2 class="page-title">订单管理管理</h2>
      <el-button v-permission="'mall_order.add'" type="primary" @click="openCreate">新增</el-button>
    </div>

    <el-card shadow="never">
      <el-form inline class="filters" @submit.prevent>
        <el-form-item label="订单号" prop="order_no">
          <el-input v-model="query.order_no" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="收货人" prop="receiver">
          <el-input v-model="query.receiver" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="联系电话" prop="phone">
          <el-input v-model="query.phone" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="订单状态" prop="status">
          <el-select v-model="query.status" clearable filterable style="width: 180px">
              <el-option key="0" label="待付款" value="0" />
              <el-option key="1" label="已付款" value="1" />
              <el-option key="2" label="已发货" value="2" />
              <el-option key="3" label="已完成" value="3" />
              <el-option key="4" label="已取消" value="4" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="query.page = 1; load()">查询</el-button>
          <el-button @click="resetQuery()">重置</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="items" row-key="id">
        <el-table-column prop="id" label="ID" width="80" />
    <el-table-column prop="order_no" label="订单号" min-width="130" show-overflow-tooltip />
    <el-table-column prop="receiver" label="收货人" min-width="130" show-overflow-tooltip />
    <el-table-column prop="phone" label="联系电话" min-width="130" show-overflow-tooltip />
    <el-table-column prop="amount" label="金额(元)" min-width="130" show-overflow-tooltip />
    <el-table-column prop="status" label="订单状态" width="90">
      <template #default="{ row }">
        <el-tag type="info">{{ statusLabels[String(row.status)] ?? row.status }}</el-tag>
      </template>
    </el-table-column>
    <el-table-column prop="user_id" label="用户ID" min-width="130" show-overflow-tooltip />
    <el-table-column prop="address" label="收货地址" min-width="130" show-overflow-tooltip />
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'mall_order.edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'mall_order.delete'" link type="danger" @click="remove(row)">删除</el-button>
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
        <el-form-item label="订单号" prop="order_no">
          <el-input v-model="form.order_no" clearable />
        </el-form-item>
        <el-form-item label="收货人" prop="receiver">
          <el-input v-model="form.receiver" clearable />
        </el-form-item>
        <el-form-item label="联系电话" prop="phone">
          <el-input v-model="form.phone" clearable />
        </el-form-item>
        <el-form-item label="金额(元)" prop="amount">
          <el-input-number v-model="form.amount" style="width: 100%" />
        </el-form-item>
        <el-form-item label="订单状态" prop="status">
          <el-select v-model="form.status" clearable filterable>
              <el-option key="0" label="待付款" value="0" />
              <el-option key="1" label="已付款" value="1" />
              <el-option key="2" label="已发货" value="2" />
              <el-option key="3" label="已完成" value="3" />
              <el-option key="4" label="已取消" value="4" />
          </el-select>
        </el-form-item>
        <el-form-item label="用户ID" prop="user_id">
          <el-input-number v-model="form.user_id" style="width: 100%" />
        </el-form-item>
        <el-form-item label="收货地址" prop="address">
          <el-input v-model="form.address" clearable />
        </el-form-item>
        <el-form-item label="备注" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="3" />
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
