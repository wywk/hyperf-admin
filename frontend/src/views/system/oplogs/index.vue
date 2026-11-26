<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Refresh, Search } from '@element-plus/icons-vue'
import { ElMessage } from '@/plugins/element'
import { exportOperationLogs, fetchOperationLogs } from '@/api/oplog'
import { saveBlob } from '@/utils/download'
import type { OperationLogItem } from '@/types'

type TagType = 'success' | 'warning' | 'danger' | 'info' | 'primary'

const METHODS: Array<{ value: string; type: TagType }> = [
  { value: 'POST', type: 'primary' },
  { value: 'PUT', type: 'warning' },
  { value: 'DELETE', type: 'danger' },
  { value: 'PATCH', type: 'success' },
]

const STATUS_TYPES: Array<{ min: number; max: number; type: TagType }> = [
  { min: 200, max: 299, type: 'success' },
  { min: 300, max: 399, type: 'info' },
  { min: 400, max: 499, type: 'warning' },
  { min: 500, max: 599, type: 'danger' },
]

const loading = ref(false)
const items = ref<OperationLogItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  page_size: 10,
  username: '',
  method: '',
  path: '',
  status_code: undefined as number | undefined,
})

function methodTag(method: string): TagType {
  return METHODS.find((item) => item.value === method)?.type ?? 'info'
}

function statusTag(code: number): TagType {
  const found = STATUS_TYPES.find((item) => code >= item.min && code <= item.max)
  return found?.type ?? 'info'
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchOperationLogs({
      page: query.page,
      page_size: query.page_size,
      username: query.username.trim() || undefined,
      method: query.method || undefined,
      path: query.path.trim() || undefined,
      status_code: query.status_code,
    })
    items.value = result.items
    total.value = result.total
  } catch {
    // 错误提示已由请求层统一处理
  } finally {
    loading.value = false
  }
}

function search(): void {
  query.page = 1
  void load()
}

function reset(): void {
  query.username = ''
  query.method = ''
  query.path = ''
  query.status_code = undefined
  query.page = 1
  void load()
}

function onSizeChange(): void {
  query.page = 1
  void load()
}

function paramsText(row: OperationLogItem): string {
  if (!row.params || Object.keys(row.params).length === 0) return '—'
  return JSON.stringify(row.params)
}

onMounted(() => {
  void load()
})

function refresh(): void {
  void load()
  ElMessage.success('已刷新')
}

const exporting = ref(false)

async function exportLogs(): Promise<void> {
  exporting.value = true
  try {
    const blob = await exportOperationLogs({
      username: query.username.trim() || undefined,
      method: query.method || undefined,
      path: query.path.trim() || undefined,
      status_code: query.status_code,
    })
    saveBlob(blob, 'operation-logs.xlsx')
    ElMessage.success('已导出（最多 5000 条）')
  } catch {
    // 错误提示已由请求层统一处理
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>操作日志</h2>
        <p class="sub">
          数据来自 <code class="mono">GET /api/operation-logs</code>，记录登录用户的写操作（POST/PUT/DELETE/PATCH），
          参数已脱敏（password/token 等敏感字段剔除）。需要 oplog.view 权限。
        </p>
      </div>
      <el-button :loading="loading" @click="refresh">
        <el-icon><Refresh /></el-icon>
        <span>刷新</span>
      </el-button>
      <el-button v-permission="'oplog.view'" :loading="exporting" @click="exportLogs">导出 Excel</el-button>
    </div>

    <el-card shadow="never" class="filter-card">
      <el-form inline @submit.prevent="search">
        <el-form-item label="请求方法">
          <el-select v-model="query.method" placeholder="全部" clearable style="width: 130px" @change="search">
            <el-option v-for="item in METHODS" :key="item.value" :label="item.value" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="用户名">
          <el-input v-model="query.username" placeholder="精确匹配" clearable style="width: 160px" @keyup.enter="search" />
        </el-form-item>
        <el-form-item label="路径">
          <el-input v-model="query.path" placeholder="模糊匹配，如 /api/roles" clearable style="width: 220px" @keyup.enter="search" />
        </el-form-item>
        <el-form-item label="状态码">
          <el-select v-model="query.status_code" placeholder="全部" clearable filterable style="width: 130px" @change="search">
            <el-option v-for="code in [200, 201, 204, 400, 401, 403, 404, 422, 500]" :key="code" :label="String(code)" :value="code" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="search">
            <el-icon><Search /></el-icon>
            <span>查询</span>
          </el-button>
          <el-button @click="reset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table v-loading="loading" :data="items" stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="username" label="操作人" width="110" />
        <el-table-column label="方法" width="90">
          <template #default="{ row }">
            <el-tag :type="methodTag(row.method)" effect="light" size="small">{{ row.method }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="path" label="路径" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono">{{ row.path }}</span>
          </template>
        </el-table-column>
        <el-table-column label="参数" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono text-muted">{{ paramsText(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态码" width="90">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status_code)" effect="light" size="small">{{ row.status_code }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="cost_ms" label="耗时" width="90">
          <template #default="{ row }">
            <span :class="{ 'slow': row.cost_ms > 1000 }">{{ row.cost_ms }}ms</span>
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="IP" width="130">
          <template #default="{ row }">
            <span class="mono">{{ row.ip }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="时间" width="170">
          <template #default="{ row }">
            <span class="mono">{{ row.created_at }}</span>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无日志" :image-size="80" />
        </template>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.page_size"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @current-change="load"
          @size-change="onSizeChange"
        />
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.filter-card,
.table-card {
  border-radius: var(--app-radius);
}

.filter-card :deep(.el-form-item) {
  margin-bottom: 0;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

code.mono {
  background: #f3f4f6;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 12px;
}

.slow {
  color: var(--el-color-danger);
  font-weight: 600;
}
</style>
