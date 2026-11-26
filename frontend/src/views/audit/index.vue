<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Download, Refresh, Search } from '@element-plus/icons-vue'
import { ElMessage } from '@/plugins/element'
import { fetchLoginLogs } from '@/api/audit'
import { createExportJob } from '@/api/jobs'
import { useJobStore } from '@/stores/jobs'
import type { LoginLogItem } from '@/types'

const jobStore = useJobStore()

type TagType = 'success' | 'warning' | 'danger' | 'info' | 'primary'

const EVENTS: Array<{ value: string; label: string; type: TagType }> = [
  { value: 'login_success', label: '登录成功', type: 'success' },
  { value: 'login_failed', label: '密码错误', type: 'danger' },
  { value: 'login_locked', label: '触发限流', type: 'warning' },
  { value: 'login_disabled', label: '账号禁用', type: 'info' },
  { value: 'logout', label: '退出登录', type: 'info' },
  { value: 'refresh_replay', label: '凭证重放', type: 'danger' },
]

const loading = ref(false)
const items = ref<LoginLogItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  page_size: 10,
  event: '',
  username: '',
})

function eventMeta(event: string): { label: string; type: TagType } {
  const found = EVENTS.find((item) => item.value === event)
  return found ? { label: found.label, type: found.type } : { label: event, type: 'info' }
}

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchLoginLogs({
      page: query.page,
      page_size: query.page_size,
      event: query.event || undefined,
      username: query.username.trim() || undefined,
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
  query.event = ''
  query.username = ''
  query.page = 1
  void load()
}

function onSizeChange(): void {
  query.page = 1
  void load()
}

onMounted(() => {
  void load()
})

function refresh(): void {
  void load()
  ElMessage.success('已刷新')
}

const exporting = ref(false)

/** 按当前筛选条件创建异步导出任务，完成后站内消息提醒，任务中心可下载 */
async function exportLogs(): Promise<void> {
  exporting.value = true
  try {
    await createExportJob('export.login_logs', {
      event: query.event || undefined,
      username: query.username.trim() || undefined,
    })
    jobStore.bump()
    ElMessage.success('已创建导出任务，完成后可在任务中心下载')
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
        <h2>登录审计</h2>
        <p class="sub">数据来自 <code class="mono">GET /api/auth/login-logs</code>，需要 audit.view 权限。</p>
      </div>
      <el-button :loading="loading" @click="refresh">
        <el-icon><Refresh /></el-icon>
        <span>刷新</span>
      </el-button>
      <el-button :loading="exporting" @click="exportLogs">
        <el-icon><Download /></el-icon>
        <span>导出 Excel</span>
      </el-button>
    </div>

    <el-card shadow="never" class="filter-card">
      <el-form inline @submit.prevent="search">
        <el-form-item label="事件">
          <el-select v-model="query.event" placeholder="全部" clearable style="width: 160px" @change="search">
            <el-option v-for="item in EVENTS" :key="item.value" :label="item.label" :value="item.value" />
          </el-select>
        </el-form-item>
        <el-form-item label="用户名">
          <el-input
            v-model="query.username"
            placeholder="精确匹配"
            clearable
            style="width: 180px"
            @keyup.enter="search"
          />
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
        <el-table-column prop="username" label="用户名" width="110" />
        <el-table-column label="事件" width="110">
          <template #default="{ row }">
            <el-tag :type="eventMeta(row.event).type" effect="light" size="small">
              {{ eventMeta(row.event).label }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="ip" label="IP" width="130">
          <template #default="{ row }">
            <span class="mono">{{ row.ip }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="message" label="说明" min-width="180" show-overflow-tooltip />
        <el-table-column prop="user_agent" label="User-Agent" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="text-muted">{{ row.user_agent || '—' }}</span>
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
  background: var(--el-fill-color-light);
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 12px;
}
</style>
