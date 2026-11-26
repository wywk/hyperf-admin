<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { Refresh, Search } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { deleteJob, fetchJobs, retryJob } from '@/api/jobs'
import { http } from '@/api/http'
import { saveBlob } from '@/utils/download'
import type { JobItem } from '@/types'

type TagType = 'success' | 'warning' | 'danger' | 'info' | 'primary'

const STATUS: Array<{ value: number; label: string; type: TagType }> = [
  { value: 0, label: '排队中', type: 'info' },
  { value: 1, label: '执行中', type: 'primary' },
  { value: 2, label: '已完成', type: 'success' },
  { value: 3, label: '失败', type: 'danger' },
]

const TYPES = [
  { value: 'export.login_logs', label: '导出登录审计' },
  { value: 'export.operation_logs', label: '导出操作日志' },
  { value: 'export.products', label: '导出商品' },
]

const loading = ref(false)
const items = ref<JobItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  page_size: 10,
  status: undefined as number | undefined,
  type: '',
})

let timer: number | undefined

function statusMeta(status: number): { label: string; type: TagType } {
  return STATUS.find((item) => item.value === status) ?? { label: String(status), type: 'info' }
}

function typeLabel(type: string): string {
  return TYPES.find((item) => item.value === type)?.label ?? type
}

function durationText(ms: number | null): string {
  if (ms === null) return '—'
  return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchJobs({
      page: query.page,
      page_size: query.page_size,
      status: query.status,
      type: query.type || undefined,
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
  query.status = undefined
  query.type = ''
  query.page = 1
  void load()
}

function onSizeChange(): void {
  query.page = 1
  void load()
}

async function retry(row: JobItem): Promise<void> {
  try {
    await retryJob(row.id)
    ElMessage.success('已重新排队')
    void load()
  } catch {
    // 错误提示已由请求层统一处理
  }
}

async function download(row: JobItem): Promise<void> {
  const blob = await http.get<Blob>(`/files/${row.result_file_id}/download`, {
    responseType: 'blob',
  })
  saveBlob(blob, `${row.type_text}-${row.id}.xlsx`)
}

async function remove(row: JobItem): Promise<void> {
  try {
    await ElMessageBox.confirm('删除后不可恢复，确定删除这条任务记录吗？', '删除任务', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  try {
    await deleteJob(row.id)
    ElMessage.success('已删除')
    void load()
  } catch {
    // 错误提示已由请求层统一处理
  }
}

/** 轮询刷新：有排队/执行中的任务时更频繁 */
function startPolling(): void {
  timer = window.setInterval(() => {
    const hasActive = items.value.some((item) => item.status === 0 || item.status === 1)
    if (hasActive) void load()
  }, 5000)
}

onMounted(() => {
  void load()
  startPolling()
})

onBeforeUnmount(() => {
  if (timer !== undefined) window.clearInterval(timer)
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>任务中心</h2>
        <p class="sub">
          异步任务的进度与结果（当前支持导出类任务）。任务完成后有站内消息提醒，结果文件可在此下载。
          无「任务中心」权限的账号只能看到自己提交的任务。
        </p>
      </div>
      <el-button :loading="loading" @click="load">
        <el-icon><Refresh /></el-icon>
        <span>刷新</span>
      </el-button>
    </div>

    <el-card shadow="never" class="filter-card">
      <el-form inline @submit.prevent="search">
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 130px" @change="search">
            <el-option v-for="item in STATUS" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="类型">
          <el-select v-model="query.type" placeholder="全部" clearable style="width: 180px" @change="search">
            <el-option v-for="item in TYPES" :key="item.value" :label="item.label" :value="item.value" />
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
        <el-table-column label="类型" min-width="140">
          <template #default="{ row }">
            {{ typeLabel(row.type) }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusMeta(row.status).type" effect="light" size="small">
              {{ statusMeta(row.status).label }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="耗时" width="90">
          <template #default="{ row }">
            {{ durationText(row.duration_ms) }}
          </template>
        </el-table-column>
        <el-table-column label="尝试次数" width="90">
          <template #default="{ row }">
            {{ row.attempts }}
          </template>
        </el-table-column>
        <el-table-column label="失败原因" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.error" class="error-text">{{ row.error }}</span>
            <span v-else class="text-muted">—</span>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="提交时间" width="170">
          <template #default="{ row }">
            <span class="mono">{{ row.created_at ?? '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="finished_at" label="结束时间" width="170">
          <template #default="{ row }">
            <span class="mono">{{ row.finished_at ?? '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button
              v-if="row.result_file_id"
              size="small"
              type="primary"
              link
              @click="download(row)"
            >
              下载结果
            </el-button>
            <el-button
              v-if="row.status === 3"
              size="small"
              type="warning"
              link
              @click="retry(row)"
            >
              重试
            </el-button>
            <el-button
              v-if="row.status === 2 || row.status === 3"
              size="small"
              type="danger"
              link
              @click="remove(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无任务" :image-size="80" />
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

.error-text {
  color: var(--el-color-danger);
}

.text-muted {
  color: var(--el-text-color-secondary);
}
</style>
