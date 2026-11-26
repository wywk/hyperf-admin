<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { Document, Refresh, Search, VideoPlay } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import {
  createCrontab,
  deleteCrontab,
  fetchCrontabLogs,
  fetchCrontabs,
  runCrontab,
  updateCrontab,
} from '@/api/crontab'
import { useAuthStore } from '@/stores/auth'
import type { CrontabLogItem, CrontabTaskItem } from '@/types'

const auth = useAuthStore()

type TagType = 'success' | 'warning' | 'danger' | 'info' | 'primary'

const loading = ref(false)
const items = ref<CrontabTaskItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  page_size: 10,
  name: '',
  status: undefined as number | undefined,
})

// ---------- 新建 / 编辑 ----------
const dialogVisible = ref(false)
const editing = ref<CrontabTaskItem | null>(null)
const saving = ref(false)
const form = reactive({
  name: '',
  handler: '',
  rule: '',
  params: '',
  status: 1,
})

function openCreate(): void {
  editing.value = null
  form.name = ''
  form.handler = 'App\\Crontab\\'
  form.rule = '*/5 * * * *'
  form.params = ''
  form.status = 1
  dialogVisible.value = true
}

function openEdit(row: CrontabTaskItem): void {
  editing.value = row
  form.name = row.name
  form.handler = row.handler
  form.rule = row.rule
  form.params = row.params ? JSON.stringify(row.params, null, 2) : ''
  form.status = row.status
  dialogVisible.value = true
}

async function save(): Promise<void> {
  let params: Record<string, unknown> | null = null
  if (form.params.trim() !== '') {
    try {
      params = JSON.parse(form.params)
    } catch {
      ElMessage.warning('任务参数不是合法 JSON')
      return
    }
  }
  const payload = {
    name: form.name.trim(),
    handler: form.handler.trim(),
    rule: form.rule.trim(),
    params,
    status: form.status,
  }
  saving.value = true
  try {
    if (editing.value) {
      await updateCrontab(editing.value.id, payload)
      ElMessage.success('已更新')
    } else {
      await createCrontab(payload)
      ElMessage.success('已创建')
    }
    dialogVisible.value = false
    void load()
  } catch {
    // 错误提示已由请求层统一处理
  } finally {
    saving.value = false
  }
}

// ---------- 状态开关 ----------
async function toggleStatus(row: CrontabTaskItem): Promise<void> {
  const next = row.status === 1 ? 0 : 1
  try {
    await updateCrontab(row.id, {
      name: row.name,
      handler: row.handler,
      rule: row.rule,
      params: row.params,
      status: next,
    })
    ElMessage.success(next === 1 ? '已启用' : '已停用')
    void load()
  } catch {
    row.status = row.status === 1 ? 1 : 0
  }
}

// ---------- 手动执行 ----------
async function run(row: CrontabTaskItem): Promise<void> {
  try {
    const log = await runCrontab(row.id)
    if (log.status === 1) {
      ElMessage.success(`执行成功（${log.cost_ms}ms）：${log.output || '无输出'}`)
    } else {
      ElMessage.error(`执行失败：${log.output}`)
    }
    void load()
  } catch {
    // 错误提示已由请求层统一处理
  }
}

// ---------- 删除 ----------
async function remove(row: CrontabTaskItem): Promise<void> {
  try {
    await ElMessageBox.confirm(`删除任务「${row.name}」将同时清空其执行日志，是否继续？`, '删除确认', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }
  try {
    await deleteCrontab(row.id)
    ElMessage.success('已删除')
    void load()
  } catch {
    // 错误提示已由请求层统一处理
  }
}

// ---------- 日志抽屉 ----------
const drawerVisible = ref(false)
const logsLoading = ref(false)
const logs = ref<CrontabLogItem[]>([])
const logsTotal = ref(0)
const logTask = ref<CrontabTaskItem | null>(null)
const logPage = reactive({ page: 1, page_size: 10 })

async function openLogs(row: CrontabTaskItem): Promise<void> {
  logTask.value = row
  logPage.page = 1
  drawerVisible.value = true
  await loadLogs()
}

async function loadLogs(): Promise<void> {
  if (!logTask.value) return
  logsLoading.value = true
  try {
    const result = await fetchCrontabLogs(logTask.value.id, { ...logPage })
    logs.value = result.items
    logsTotal.value = result.total
  } catch {
    // 错误提示已由请求层统一处理
  } finally {
    logsLoading.value = false
  }
}

function statusTag(status: number): TagType {
  if (status === 1) return 'success'
  if (status === 2) return 'danger'
  return 'info'
}

// ---------- 列表 ----------
async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchCrontabs({
      page: query.page,
      page_size: query.page_size,
      name: query.name.trim() || undefined,
      status: query.status,
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
  query.name = ''
  query.status = undefined
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
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>定时任务</h2>
        <p class="sub">
          后端调度进程每秒扫描到期任务；任务类须实现 <code class="mono">App\Crontab\TaskInterface</code>。需要 crontab.* 权限。
        </p>
      </div>
      <div>
        <el-button v-permission="'crontab.add'" type="primary" @click="openCreate">新建任务</el-button>
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
      </div>
    </div>

    <el-card shadow="never" class="filter-card">
      <el-form inline @submit.prevent="search">
        <el-form-item label="任务名称">
          <el-input v-model="query.name" placeholder="模糊匹配" clearable style="width: 180px" @keyup.enter="search" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 120px" @change="search">
            <el-option label="启用" :value="1" />
            <el-option label="停用" :value="0" />
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
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="name" label="任务名称" min-width="140" show-overflow-tooltip />
        <el-table-column prop="rule" label="cron 表达式" width="120">
          <template #default="{ row }">
            <span class="mono">{{ row.rule }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="handler" label="处理类" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <span class="mono text-muted">{{ row.handler }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-switch
              v-if="auth.hasAnyPermission(['crontab.edit'])"
              :model-value="row.status === 1"
              @change="toggleStatus(row)"
            />
            <el-tag v-else :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="last_run_at" label="最近执行" width="165">
          <template #default="{ row }">
            <span class="mono">{{ row.last_run_at || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="next_run_at" label="下次执行" width="165">
          <template #default="{ row }">
            <span class="mono">{{ row.next_run_at || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="连续失败" width="90">
          <template #default="{ row }">
            <el-tag v-if="row.consecutive_failures > 0" type="danger" size="small">{{ row.consecutive_failures }}</el-tag>
            <span v-else class="text-muted">0</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="220" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'crontab.run'" link type="primary" @click="run(row)">
              <el-icon><VideoPlay /></el-icon>
              <span>执行</span>
            </el-button>
            <el-button v-permission="'crontab.view'" link @click="openLogs(row)">
              <el-icon><Document /></el-icon>
              <span>日志</span>
            </el-button>
            <el-button v-permission="'crontab.edit'" link @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'crontab.delete'" link type="danger" @click="remove(row)">删除</el-button>
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

    <!-- 新建 / 编辑 -->
    <el-dialog v-model="dialogVisible" :title="editing ? '编辑任务' : '新建任务'" width="560px">
      <el-form label-width="110px">
        <el-form-item label="任务名称" required>
          <el-input v-model="form.name" maxlength="64" placeholder="如 每日运营日报" />
        </el-form-item>
        <el-form-item label="处理类" required>
          <el-input v-model="form.handler" placeholder="App\Crontab\DemoReportTask" class="mono" />
          <div class="tip">须实现 App\Crontab\TaskInterface，且全局唯一</div>
        </el-form-item>
        <el-form-item label="cron 表达式" required>
          <el-input v-model="form.rule" placeholder="*/5 * * * *" class="mono" />
          <div class="tip">标准 5 位表达式：分 时 日 月 周</div>
        </el-form-item>
        <el-form-item label="任务参数">
          <el-input
            v-model="form.params"
            type="textarea"
            :rows="3"
            placeholder='可选，JSON 对象，如 {"title": "日报"}'
            class="mono"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </template>
    </el-dialog>

    <!-- 执行日志 -->
    <el-drawer v-model="drawerVisible" :title="`执行日志：${logTask?.name ?? ''}`" size="640px">
      <el-table v-loading="logsLoading" :data="logs" stripe size="small">
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="output" label="输出" min-width="240" show-overflow-tooltip />
        <el-table-column prop="cost_ms" label="耗时" width="80">
          <template #default="{ row }">{{ row.cost_ms }}ms</template>
        </el-table-column>
        <el-table-column prop="created_at" label="时间" width="160">
          <template #default="{ row }">
            <span class="mono">{{ row.created_at }}</span>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无执行记录" :image-size="60" />
        </template>
      </el-table>
      <div class="pager">
        <el-pagination
          v-model:current-page="logPage.page"
          v-model:page-size="logPage.page_size"
          :total="logsTotal"
          layout="total, prev, pager, next"
          background
          small
          @current-change="loadLogs"
        />
      </div>
    </el-drawer>
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

.tip {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  line-height: 1.4;
  margin-top: 2px;
}
</style>
