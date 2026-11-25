<template>
  <div class="dict-page">
    <el-row :gutter="16">
      <!-- 左：字典类型 -->
      <el-col :span="9" :xs="24">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <span>字典类型</span>
              <div class="head-actions">
                <el-input
                  v-model="typeKeyword"
                  placeholder="名称/编码"
                  clearable
                  style="width: 140px"
                  @keyup.enter="loadTypes"
                  @clear="loadTypes"
                >
                  <template #append>
                    <el-button :icon="Search" @click="loadTypes" />
                  </template>
                </el-input>
                <el-button v-permission="'dict.add'" type="primary" :icon="Plus" @click="openTypeDialog()">新增</el-button>
              </div>
            </div>
          </template>

          <el-table
            v-loading="typeLoading"
            :data="types"
            highlight-current-row
            @current-change="onSelectType"
          >
            <el-table-column prop="name" label="名称" min-width="110" show-overflow-tooltip />
            <el-table-column prop="code" label="编码" min-width="130" show-overflow-tooltip>
              <template #default="{ row }">
                <el-text size="small" type="info">{{ row.code }}</el-text>
              </template>
            </el-table-column>
            <el-table-column prop="item_count" label="项数" width="60" align="center" />
            <el-table-column label="状态" width="70" align="center">
              <template #default="{ row }">
                <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
                  {{ row.status === 1 ? '启用' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="110" align="center">
              <template #default="{ row }">
                <el-button v-permission="'dict.edit'" link type="primary" @click="openTypeDialog(row)">编辑</el-button>
                <el-button v-permission="'dict.delete'" link type="danger" @click="removeType(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>

      <!-- 右：字典项 -->
      <el-col :span="15" :xs="24">
        <el-card shadow="never">
          <template #header>
            <div class="card-head">
              <span>
                字典项
                <el-tag v-if="currentType" size="small" type="primary" style="margin-left: 8px">{{ currentType.name }}（{{ currentType.code }}）</el-tag>
              </span>
              <el-button v-permission="'dict.add'" type="primary" :icon="Plus" :disabled="!currentType" @click="openItemDialog()">新增字典项</el-button>
            </div>
          </template>

          <el-empty v-if="!currentType" description="左侧选择一个字典类型" />
          <el-table v-else v-loading="itemLoading" :data="items">
            <el-table-column prop="label" label="显示文本" min-width="120" />
            <el-table-column prop="value" label="值" min-width="110">
              <template #default="{ row }">
                <el-text size="small" type="info">{{ row.value }}</el-text>
              </template>
            </el-table-column>
            <el-table-column prop="sort" label="排序" width="70" align="center" />
            <el-table-column label="状态" width="90" align="center">
              <template #default="{ row }">
                <el-switch
                  :model-value="row.status === 1"
                  :disabled="!canEdit"
                  @change="(v: string | number | boolean) => toggleItem(row, v)"
                />
              </template>
            </el-table-column>
            <el-table-column prop="remark" label="备注" min-width="110" show-overflow-tooltip />
            <el-table-column label="操作" width="110" align="center">
              <template #default="{ row }">
                <el-button v-permission="'dict.edit'" link type="primary" @click="openItemDialog(row)">编辑</el-button>
                <el-button v-permission="'dict.delete'" link type="danger" @click="removeItem(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>
    </el-row>

    <!-- 类型弹窗 -->
    <el-dialog v-model="typeDialog.visible" :title="typeDialog.row ? '编辑字典类型' : '新增字典类型'" width="460px">
      <el-form :model="typeDialog.form" label-width="80px">
        <el-form-item label="名称" required>
          <el-input v-model="typeDialog.form.name" maxlength="64" placeholder="如 订单状态" />
        </el-form-item>
        <el-form-item label="编码" required>
          <el-input v-model="typeDialog.form.code" :disabled="!!typeDialog.row" maxlength="64" placeholder="如 order_status（创建后不可改）" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="typeDialog.form.description" type="textarea" :rows="2" maxlength="255" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="typeDialog.form.sort" :min="0" />
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="typeDialog.form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="typeDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="typeDialog.saving" @click="saveType">保存</el-button>
      </template>
    </el-dialog>

    <!-- 字典项弹窗 -->
    <el-dialog v-model="itemDialog.visible" :title="itemDialog.row ? '编辑字典项' : '新增字典项'" width="460px">
      <el-form :model="itemDialog.form" label-width="80px">
        <el-form-item label="显示文本" required>
          <el-input v-model="itemDialog.form.label" maxlength="64" placeholder="如 已支付" />
        </el-form-item>
        <el-form-item label="值" required>
          <el-input v-model="itemDialog.form.value" maxlength="64" placeholder="同类型内唯一，如 paid" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="itemDialog.form.sort" :min="0" />
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="itemDialog.form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="itemDialog.form.remark" type="textarea" :rows="2" maxlength="255" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="itemDialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="itemDialog.saving" @click="saveItem">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { Plus, Search } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { useAuthStore } from '@/stores/auth'
import {
  createDictItem,
  createDictType,
  fetchDictItems,
  fetchDictTypes,
  removeDictItem,
  removeDictType,
  updateDictItem,
  updateDictType,
} from '@/api/dict'
import type { DictItem, DictType } from '@/api/dict'

const auth = useAuthStore()
const canEdit = computed(() => auth.hasPermission('dict.edit'))

const typeKeyword = ref('')
const typeLoading = ref(false)
const types = ref<DictType[]>([])
const currentType = ref<DictType | null>(null)

const itemLoading = ref(false)
const items = ref<DictItem[]>([])

const typeDialog = reactive({
  visible: false,
  saving: false,
  row: null as DictType | null,
  form: { name: '', code: '', description: '', sort: 0, status: 1 },
})

const itemDialog = reactive({
  visible: false,
  saving: false,
  row: null as DictItem | null,
  form: { label: '', value: '', sort: 0, status: 1, remark: '' },
})

onMounted(() => {
  loadTypes()
})

async function loadTypes(): Promise<void> {
  typeLoading.value = true
  try {
    const result = await fetchDictTypes(typeKeyword.value.trim() || undefined)
    types.value = result.items
    // 保持已选中类型（数据被刷新后引用可能仍在列表里）
    if (currentType.value) {
      const found = types.value.find((t) => t.id === currentType.value!.id)
      currentType.value = found ?? null
    }
    if (currentType.value) {
      await loadItems(currentType.value.id)
    } else {
      items.value = []
    }
  } finally {
    typeLoading.value = false
  }
}

async function onSelectType(row: DictType | null): Promise<void> {
  currentType.value = row
  if (row) {
    await loadItems(row.id)
  } else {
    items.value = []
  }
}

async function loadItems(typeId: number): Promise<void> {
  itemLoading.value = true
  try {
    items.value = (await fetchDictItems(typeId)).items
  } finally {
    itemLoading.value = false
  }
}

// ---------- 类型 ----------

function openTypeDialog(row?: DictType): void {
  typeDialog.row = row ?? null
  typeDialog.form = row
    ? { name: row.name, code: row.code, description: row.description, sort: row.sort, status: row.status }
    : { name: '', code: '', description: '', sort: 0, status: 1 }
  typeDialog.visible = true
}

async function saveType(): Promise<void> {
  if (!typeDialog.form.name.trim() || !typeDialog.form.code.trim()) {
    ElMessage.warning('名称与编码必填')
    return
  }
  typeDialog.saving = true
  try {
    if (typeDialog.row) {
      await updateDictType(typeDialog.row.id, {
        name: typeDialog.form.name,
        description: typeDialog.form.description,
        sort: typeDialog.form.sort,
        status: typeDialog.form.status,
      })
    } else {
      await createDictType({ ...typeDialog.form })
    }
    ElMessage.success('保存成功')
    typeDialog.visible = false
    await loadTypes()
  } finally {
    typeDialog.saving = false
  }
}

async function removeType(row: DictType): Promise<void> {
  await ElMessageBox.confirm(
    `删除字典「${row.name}」将同时删除其下 ${row.item_count} 个字典项，且业务侧立即读不到该字典。确定删除？`,
    '删除确认',
    { type: 'warning' },
  )
  await removeDictType(row.id)
  ElMessage.success('已删除')
  if (currentType.value?.id === row.id) {
    currentType.value = null
    items.value = []
  }
  await loadTypes()
}

// ---------- 字典项 ----------

function openItemDialog(row?: DictItem): void {
  itemDialog.row = row ?? null
  itemDialog.form = row
    ? { label: row.label, value: row.value, sort: row.sort, status: row.status, remark: row.remark }
    : { label: '', value: '', sort: 0, status: 1, remark: '' }
  itemDialog.visible = true
}

async function saveItem(): Promise<void> {
  if (!itemDialog.form.label.trim() || !itemDialog.form.value.trim()) {
    ElMessage.warning('显示文本与值必填')
    return
  }
  if (!currentType.value) {
    return
  }
  itemDialog.saving = true
  try {
    if (itemDialog.row) {
      await updateDictItem(itemDialog.row.id, { ...itemDialog.form })
    } else {
      await createDictItem(currentType.value.id, { ...itemDialog.form })
    }
    ElMessage.success('保存成功')
    itemDialog.visible = false
    await loadItems(currentType.value.id)
    await loadTypes()
  } finally {
    itemDialog.saving = false
  }
}

async function toggleItem(row: DictItem, value: string | number | boolean): Promise<void> {
  await updateDictItem(row.id, { status: value ? 1 : 0 })
  row.status = value ? 1 : 0
  ElMessage.success(value ? '已启用' : '已停用')
}

async function removeItem(row: DictItem): Promise<void> {
  await ElMessageBox.confirm(`确定删除字典项「${row.label}」？`, '删除确认', { type: 'warning' })
  await removeDictItem(row.id)
  ElMessage.success('已删除')
  if (currentType.value) {
    await loadItems(currentType.value.id)
  }
  await loadTypes()
}
</script>

<style scoped>
.dict-page :deep(.el-card__header) {
  padding: 12px 16px;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
