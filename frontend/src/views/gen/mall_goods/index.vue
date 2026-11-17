<script setup lang="ts">
/**
 * MallGoods 管理页（由代码生成器生成，可按需调整）。
 * 列表 / 搜索 / 新增 / 编辑 / 删除，权限码 mall_goods.view/.add/.edit/.delete。
 */
import { onMounted, reactive, ref } from 'vue'
import {
  createMallGoods,
  deleteMallGoods,
  fetchMallGoodsList,
  updateMallGoods,
} from '@/api/gen/mall_goods'
import {
  createMallGoodsSku,
  deleteMallGoodsSku,
  fetchMallGoodsSkuList,
  updateMallGoodsSku,
} from '@/api/gen/mall_goods_sku'
import { ElMessage, ElMessageBox } from '@/plugins/element'

const loading = ref(false)
const statusLabels: Record<string, string> = {
  '1': '上架',
  '0': '下架',
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
    const result = await fetchMallGoodsList({ ...query })
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
  category_id: [{ required: true, message: '请填写分类', trigger: 'blur' }],
  price: [{ required: true, message: '请填写售价(元)', trigger: 'blur' }],
}
const emptyForm: Record<string, any> = {
  name: '',
  category_id: null,
  price: null,
  stock: null,
  status: '',
  cover: '',
  summary: '',
}

function openCreate(): void {
  editingId.value = 0
  resetForm()
  Object.assign(form, JSON.parse(JSON.stringify(emptyForm)))
  skuItems.value = []
  dialogVisible.value = true
}

function openEdit(row: any): void {
  editingId.value = row.id
  resetForm()
  Object.assign(form, JSON.parse(JSON.stringify(emptyForm)))
  Object.assign(form, JSON.parse(JSON.stringify(row)))
  delete form.id
  dialogVisible.value = true
  void loadSkus(row.id)
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
    let goodsId = editingId.value
    if (goodsId > 0) {
      await updateMallGoods(goodsId, { ...form })
      ElMessage.success('已保存')
    } else {
      const created = await createMallGoods({ ...form })
      goodsId = created.id
      ElMessage.success('已创建')
    }

    // 商品新增/编辑弹窗内暂存的 SKU（无 id 的本地行）：保存商品后统一创建
    for (const sku of skuItems.value.filter((s) => !s.id)) {
      const { _key, ...payload } = sku
      await createMallGoodsSku({ goods_id: goodsId, ...payload })
    }
    if (skuItems.value.some((s) => !s.id)) {
      ElMessage.success('SKU 已保存')
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
    await deleteMallGoods(row.id)
    ElMessage.success('已删除')
    await load()
  } catch {
    // 错误提示由请求层统一处理
  }
}

/* ---------------- SKU 规格（在商品新增/编辑弹窗内维护） ---------------- */

const skuItems = ref<any[]>([])
const skuLoading = ref(false)
let skuLocalSeq = 0
const skuStatusLabels: Record<string, string> = {
  '1': '启用',
  '0': '停用',
}

/** 编辑已有商品时加载其 SKU；新增时为空（保存商品后统一创建） */
async function loadSkus(goodsId: number): Promise<void> {
  skuLoading.value = true
  try {
    const result = await fetchMallGoodsSkuList({ goods_id: goodsId, page_size: 100 })
    skuItems.value = result.items
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    skuLoading.value = false
  }
}

const skuDialogVisible = ref(false)
const skuSubmitting = ref(false)
const skuEditingKey = ref<string | number>('')
const skuFormRef = ref()
const skuForm = reactive<Record<string, any>>({})
const skuRules: Record<string, unknown> = {
  sku_code: [{ required: true, message: '请填写 SKU 编码', trigger: 'blur' }],
  price: [{ required: true, message: '请填写售价', trigger: 'blur' }],
}
const emptySkuForm: Record<string, any> = {
  sku_code: '',
  specs: '',
  price: null,
  stock: null,
  status: '1',
}

function openSkuCreate(): void {
  skuEditingKey.value = ''
  resetSkuForm()
  Object.assign(skuForm, JSON.parse(JSON.stringify(emptySkuForm)))
  skuDialogVisible.value = true
}

function openSkuEdit(row: any): void {
  skuEditingKey.value = row.id ?? row._key
  resetSkuForm()
  Object.assign(skuForm, JSON.parse(JSON.stringify(emptySkuForm)))
  Object.assign(skuForm, JSON.parse(JSON.stringify(row)))
  delete skuForm.id
  delete skuForm.goods_id
  delete skuForm._key
  skuDialogVisible.value = true
}

function resetSkuForm(): void {
  for (const key of Object.keys(skuForm)) {
    delete skuForm[key]
  }
}

async function submitSku(): Promise<void> {
  const valid = await skuFormRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  submittingSku()
}

async function submittingSku(): Promise<void> {
  skuSubmitting.value = true
  try {
    if (skuEditingKey.value !== '') {
      const existing = skuItems.value.find((s) => (s.id ?? s._key) === skuEditingKey.value)
      if (existing?.id) {
        // 已入库的 SKU：实时保存
        await updateMallGoodsSku(existing.id, { ...skuForm })
        Object.assign(existing, skuForm)
      } else if (existing) {
        // 本地暂存的行：直接改内存
        Object.assign(existing, skuForm)
      }
      ElMessage.success('已保存')
    } else {
      // 新增：先本地暂存，随商品保存统一提交
      skuItems.value.push({ ...skuForm, _key: `local-${++skuLocalSeq}` })
      ElMessage.success('已添加，保存商品后生效')
    }
    skuDialogVisible.value = false
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    skuSubmitting.value = false
  }
}

async function removeSku(row: any): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除 SKU「${row.sku_code}」吗？`, '删除', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  if (!row.id) {
    skuItems.value = skuItems.value.filter((s) => s._key !== row._key)
    return
  }

  try {
    await deleteMallGoodsSku(row.id)
    ElMessage.success('已删除')
    await loadSkus(row.goods_id ?? editingId.value)
  } catch {
    // 错误提示由请求层统一处理
  }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <h2 class="page-title">商品管理管理</h2>
      <el-button v-permission="'mall_goods.add'" type="primary" @click="openCreate">新增</el-button>
    </div>

    <el-card shadow="never">
      <el-form inline class="filters" @submit.prevent>
        <el-form-item label="商品名称" prop="name">
          <el-input v-model="query.name" clearable style="width: 180px" />
        </el-form-item>
        <el-form-item label="分类" prop="category_id">
          <el-input-number v-model="query.category_id" style="width: 100%" />
        </el-form-item>
        <el-form-item label="售价(元)" prop="price">
          <el-input-number v-model="query.price" style="width: 100%" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="query.status" clearable filterable style="width: 180px">
              <el-option key="1" label="上架" value="1" />
              <el-option key="0" label="下架" value="0" />
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
    <el-table-column prop="category_id" label="分类" min-width="130" show-overflow-tooltip />
    <el-table-column prop="price" label="售价(元)" min-width="130" show-overflow-tooltip />
    <el-table-column prop="stock" label="库存" min-width="130" show-overflow-tooltip />
    <el-table-column prop="status" label="状态" width="90">
      <template #default="{ row }">
        <el-tag type="info">{{ statusLabels[String(row.status)] ?? row.status }}</el-tag>
      </template>
    </el-table-column>
    <el-table-column prop="summary" label="简介" min-width="130" show-overflow-tooltip />
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'mall_goods.edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'mall_goods.delete'" link type="danger" @click="remove(row)">删除</el-button>
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
        <el-form-item label="分类" prop="category_id">
          <el-input-number v-model="form.category_id" style="width: 100%" />
        </el-form-item>
        <el-form-item label="售价(元)" prop="price">
          <el-input-number v-model="form.price" style="width: 100%" />
        </el-form-item>
        <el-form-item label="库存" prop="stock">
          <el-input-number v-model="form.stock" :min="0" style="width: 100%" />
          <div class="field-tip">已配置 SKU 的商品，库存由各 SKU 库存求和自动回写</div>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="form.status" clearable filterable>
              <el-option key="1" label="上架" value="1" />
              <el-option key="0" label="下架" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="封面图" prop="cover">
          <el-input v-model="form.cover" clearable />
        </el-form-item>
        <el-form-item label="简介" prop="summary">
          <el-input v-model="form.summary" type="textarea" :rows="3" />
        </el-form-item>
      </el-form>

      <!-- SKU 规格：新增时先暂存，保存商品后统一创建；编辑时已有 SKU 实时保存 -->
      <el-divider content-position="left">SKU 规格（颜色/容量等，库存自动汇总到商品）</el-divider>
      <div class="sku-toolbar">
        <el-button v-permission="'mall_goods_sku.add'" type="primary" size="small" @click="openSkuCreate">新增 SKU</el-button>
      </div>
      <el-table v-loading="skuLoading" :data="skuItems" :row-key="(row: any) => row.id ?? row._key" size="small">
        <el-table-column prop="sku_code" label="SKU 编码" min-width="140" show-overflow-tooltip />
        <el-table-column prop="specs" label="规格" min-width="170" show-overflow-tooltip />
        <el-table-column prop="price" label="售价(元)" width="100" />
        <el-table-column prop="stock" label="库存" width="80" />
        <el-table-column label="状态" width="75">
          <template #default="{ row }">
            <el-tag :type="Number(row.status) === 1 ? 'success' : 'info'" size="small">
              {{ skuStatusLabels[String(row.status)] ?? row.status }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'mall_goods_sku.edit'" link type="primary" size="small" @click="openSkuEdit(row)">编辑</el-button>
            <el-button v-permission="'mall_goods_sku.delete'" link type="danger" size="small" @click="removeSku(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无 SKU" :image-size="60" />
        </template>
      </el-table>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">保存</el-button>
      </template>
    </el-dialog>

    <!-- SKU 新增/编辑（嵌套弹窗） -->
    <el-dialog v-model="skuDialogVisible" :title="skuEditingKey !== '' ? '编辑 SKU' : '新增 SKU'" width="480px" append-to-body>
      <el-form ref="skuFormRef" :model="skuForm" :rules="skuRules" label-width="90px">
        <el-form-item label="SKU 编码" prop="sku_code">
          <el-input v-model="skuForm.sku_code" clearable placeholder="如 IP15PM-BK-512" />
        </el-form-item>
        <el-form-item label="规格" prop="specs">
          <el-input v-model="skuForm.specs" clearable placeholder="如 颜色:黑色;存储:512G" />
        </el-form-item>
        <el-form-item label="售价(元)" prop="price">
          <el-input-number v-model="skuForm.price" :min="0" :precision="2" style="width: 100%" />
        </el-form-item>
        <el-form-item label="库存" prop="stock">
          <el-input-number v-model="skuForm.stock" :min="0" style="width: 100%" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="skuForm.status" style="width: 100%">
            <el-option label="启用" value="1" />
            <el-option label="停用" value="0" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="skuDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="skuSubmitting" @click="submitSku">保存</el-button>
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

.sku-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 10px;
}

.field-tip {
  width: 100%;
  margin-top: 2px;
  font-size: 12px;
  line-height: 1.4;
  color: var(--el-text-color-secondary);
}
</style>
