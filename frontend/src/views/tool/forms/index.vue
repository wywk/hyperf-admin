<script setup lang="ts">
/**
 * 表单管理：列表 + 新建/编辑（跳设计器）+ 提交数据查看。
 */
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { deleteForm, fetchForms, fetchSubmissions, type SubmissionsResult } from '@/api/forms'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import type { FormDefinition, FormSubmissionItem } from '@/types'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

/* ---------------- 列表 ---------------- */

const loading = ref(false)
const items = ref<FormDefinition[]>([])
const total = ref(0)
const query = reactive({ page: 1, page_size: 10, keyword: '' })

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchForms({ ...query })
    items.value = result.items
    total.value = result.total
  } catch {
    // 错误由请求层统一提示
  } finally {
    loading.value = false
  }
}

onMounted(load)

function search(): void {
  query.page = 1
  void load()
}

function openDesigner(row?: FormDefinition): void {
  const id = row ? `?id=${row.id}` : ''
  router.push(`/tool/forms/designer${id}`)
}

async function remove(row: FormDefinition): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `删除「${row.name}」后提交的数据一并删除。确定吗？`,
      '删除表单',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await deleteForm(row.id)
    ElMessage.success('已删除')
    await load()
  } catch {
    // 错误提示由请求层统一处理
  }
}

/* ---------------- 提交数据 ---------------- */

const drawerVisible = ref(false)
const drawerLoading = ref(false)
const currentForm = ref<FormDefinition | null>(null)
const submissionFields = ref<{ field: string; label: string }[]>([])
const submissions = ref<FormSubmissionItem[]>([])
const submissionTotal = ref(0)
const submissionPage = ref(1)

async function openSubmissions(row: FormDefinition): Promise<void> {
  drawerVisible.value = true
  currentForm.value = row
  submissionPage.value = 1
  await loadSubmissions(row.id)
}

async function loadSubmissions(id: number): Promise<void> {
  drawerLoading.value = true
  try {
    const result: SubmissionsResult = await fetchSubmissions(id, {
      page: submissionPage.value,
      page_size: 10,
    })
    submissions.value = result.items
    submissionTotal.value = result.total
    submissionFields.value = (result.form.schema?.fields ?? []).map((field) => ({
      field: field.field,
      label: field.label,
    }))
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    drawerLoading.value = false
  }
}

function formatValue(value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return '-'
  }
  if (Array.isArray(value)) {
    return value.join('、')
  }
  if (typeof value === 'boolean') {
    return value ? '是' : '否'
  }
  return String(value)
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2 class="page-title">表单管理</h2>
        <p class="page-desc">拖拽设计表单，导出 JSON Schema；发布后通过公开接口收集提交数据。</p>
      </div>
      <el-button v-permission="'form.add'" type="primary" @click="openDesigner()">新建表单</el-button>
    </div>

    <el-card shadow="never">
      <div class="filters">
        <el-input
          v-model="query.keyword"
          placeholder="按名称/标识搜索"
          clearable
          style="width: 240px"
          @keyup.enter="search"
          @clear="search"
        />
        <el-button @click="search">查询</el-button>
      </div>

      <el-table v-loading="loading" :data="items" row-key="id">
        <el-table-column prop="name" label="表单名称" min-width="160" show-overflow-tooltip />
        <el-table-column prop="code" label="标识" min-width="140">
          <template #default="{ row }">
            <span class="mono">/api/form/{{ row.code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="description" label="说明" min-width="180" show-overflow-tooltip />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '已发布' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="updated_at" label="更新时间" width="170" />
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="auth.hasPermission('form.edit')"
              link
              type="primary"
              @click="openDesigner(row)"
            >
              设计
            </el-button>
            <el-button
              v-if="auth.hasPermission('form.view')"
              link
              type="primary"
              @click="openSubmissions(row)"
            >
              提交数据
            </el-button>
            <el-tooltip v-if="!auth.hasPermission('form.delete')" content="没有删除权限" placement="top">
              <span class="disabled-action">删除</span>
            </el-tooltip>
            <el-button v-else link type="danger" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="还没有表单，点右上角新建" :image-size="80" />
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

    <!-- 提交数据抽屉：列由表单 Schema 动态生成 -->
    <el-drawer v-model="drawerVisible" :title="`提交数据 · ${currentForm?.name ?? ''}`" size="62%">
      <div v-loading="drawerLoading">
        <el-table :data="submissions" row-key="id">
          <el-table-column prop="id" label="#" width="70" />
          <el-table-column
            v-for="col in submissionFields"
            :key="col.field"
            :label="col.label"
            min-width="130"
            show-overflow-tooltip
          >
            <template #default="{ row }">
              {{ formatValue(row.data?.[col.field]) }}
            </template>
          </el-table-column>
          <el-table-column prop="client_ip" label="IP" width="130" />
          <el-table-column prop="created_at" label="提交时间" width="170" />
          <template #empty>
            <el-empty description="还没有提交数据" :image-size="70" />
          </template>
        </el-table>

        <div class="pagination">
          <el-pagination
            v-model:current-page="submissionPage"
            :total="submissionTotal"
            :page-size="10"
            layout="total, prev, pager, next"
            background
            @current-change="currentForm && loadSubmissions(currentForm.id)"
          />
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 14px;
}

.page-title {
  margin: 0 0 4px;
  font-size: 20px;
}

.page-desc {
  margin: 0;
  font-size: 13px;
  color: var(--app-muted);
}

.filters {
  display: flex;
  gap: 10px;
  margin-bottom: 14px;
}

.pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}

.mono {
  font-family: 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px;
}

.disabled-action {
  font-size: 12px;
  color: var(--el-text-color-disabled);
  cursor: not-allowed;
}
</style>
