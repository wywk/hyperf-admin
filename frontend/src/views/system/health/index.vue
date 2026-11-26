<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { Refresh } from '@element-plus/icons-vue'
import { ElMessage } from '@/plugins/element'
import { fetchHealth } from '@/api/health'
import type { HealthOverview } from '@/api/health'

const loading = ref(false)
const data = ref<HealthOverview>()

let timer: number | undefined

async function load(silent = false): Promise<void> {
  if (!silent) loading.value = true
  try {
    data.value = await fetchHealth()
  } catch {
    // 错误提示已由请求层统一处理
  } finally {
    if (!silent) loading.value = false
  }
}

function percentType(percent: number | null | undefined): 'success' | 'warning' | 'danger' | 'info' {
  if (percent === null || percent === undefined) return 'info'
  if (percent >= 90) return 'danger'
  if (percent >= 75) return 'warning'
  return 'success'
}

function hitClass(rate: number | null | undefined): string {
  return rate !== null && rate !== undefined && rate < 50 ? 'num-danger' : ''
}

function diskDanger(percent: number | null | undefined): string {
  return percent !== null && percent !== undefined && percent >= 90 ? 'num-danger' : ''
}

function queueAlarm(overview: HealthOverview): boolean {
  return (overview.queue.waiting ?? 0) + (overview.queue.delayed ?? 0) > 10
}

function shortTime(value: string | null): string {
  return value ? String(value).replace('T', ' ').slice(0, 19) : '—'
}

function errText(section: { error?: string } | undefined): string {
  return section?.error ? `读取失败：${section.error}` : ''
}

async function copyErrors(): Promise<void> {
  if (!data.value?.recent_errors.length) return
  await navigator.clipboard.writeText(data.value.recent_errors.join('\n'))
  ElMessage.success('已复制到剪贴板')
}

onMounted(() => {
  void load()
  timer = window.setInterval(() => void load(true), 30000)
})

onBeforeUnmount(() => {
  if (timer !== undefined) window.clearInterval(timer)
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>系统监控</h2>
        <p class="sub">
          数据库 / 缓存 / 运行时 / 队列 / 磁盘 / 定时任务 / 接口耗时的聚合快照，每 30 秒自动刷新。
          数据来源 <code class="mono">GET /api/health</code>，只读无副作用。
        </p>
      </div>
      <div class="head-right">
        <span v-if="data" class="text-muted collected">采集于 {{ data.collected_at }}</span>
        <el-button :loading="loading" @click="load()">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
      </div>
    </div>

    <el-row v-if="data" :gutter="16">
      <el-col :span="6">
        <el-card shadow="never" class="metric-card">
          <template #header>MySQL</template>
          <el-alert v-if="data.database.error" type="error" :title="errText(data.database)" :closable="false" />
          <template v-else>
            <div class="metric-main">{{ data.database.version }}</div>
            <ul class="metric-list">
              <li><span>活跃连接</span><b>{{ data.database.threads_connected }}</b></li>
              <li><span>慢查询累计</span><b>{{ data.database.slow_queries }}</b></li>
              <li><span>已运行</span><b>{{ data.database.uptime_days }} 天</b></li>
            </ul>
          </template>
        </el-card>
      </el-col>

      <el-col :span="6">
        <el-card shadow="never" class="metric-card">
          <template #header>Redis</template>
          <el-alert v-if="data.redis.error" type="error" :title="errText(data.redis)" :closable="false" />
          <template v-else>
            <div class="metric-main">{{ data.redis.used_memory_human }}</div>
            <ul class="metric-list">
              <li><span>客户端连接</span><b>{{ data.redis.connected_clients }}</b></li>
              <li><span>键总数</span><b>{{ data.redis.total_keys }}</b></li>
              <li><span>缓存命中率</span><b :class="hitClass(data.redis.hit_rate)">{{ data.redis.hit_rate ?? '—' }}{{ data.redis.hit_rate !== null ? '%' : '' }}</b></li>
            </ul>
          </template>
        </el-card>
      </el-col>

      <el-col :span="6">
        <el-card shadow="never" class="metric-card">
          <template #header>运行时</template>
          <div class="metric-main">PHP {{ data.runtime.php }}</div>
          <ul class="metric-list">
            <li><span>Swoole</span><b>{{ data.runtime.swoole }}</b></li>
            <li><span>当前内存</span><b>{{ data.runtime.memory_used }} MB</b></li>
            <li><span>峰值内存</span><b>{{ data.runtime.memory_peak }} MB</b></li>
          </ul>
        </el-card>
      </el-col>

      <el-col :span="6">
        <el-card shadow="never" class="metric-card">
          <template #header>磁盘</template>
          <el-alert v-if="data.disk.error" type="error" :title="errText(data.disk)" :closable="false" />
          <template v-else>
            <div class="metric-main" :class="diskDanger(data.disk.used_percent)">
              {{ data.disk.used_percent ?? '—' }}%
            </div>
            <el-progress
              :percentage="data.disk.used_percent ?? 0"
              :status="percentType(data.disk.used_percent) === 'danger' ? 'exception' : 'success'"
              :stroke-width="10"
              style="margin: 8px 0"
            />
            <ul class="metric-list">
              <li><span>总容量</span><b>{{ data.disk.total_gb }} GB</b></li>
              <li><span>剩余</span><b>{{ data.disk.free_gb }} GB</b></li>
            </ul>
          </template>
        </el-card>
      </el-col>
    </el-row>

    <el-row v-if="data" :gutter="16" style="margin-top: 16px">
      <el-col :span="8">
        <el-card shadow="never" class="metric-card">
          <template #header>
            队列积压
            <el-tag v-if="queueAlarm(data)" type="warning" size="small" style="margin-left: 8px">积压</el-tag>
          </template>
          <el-alert v-if="data.queue.error" type="error" :title="errText(data.queue)" :closable="false" />
          <div v-else class="queue-grid">
            <div class="queue-item">
              <div class="queue-num">{{ data.queue.waiting }}</div>
              <div class="queue-label">等待中</div>
            </div>
            <div class="queue-item">
              <div class="queue-num">{{ data.queue.delayed }}</div>
              <div class="queue-label">延迟中</div>
            </div>
            <div class="queue-item">
              <div class="queue-num" :class="{ 'num-danger': (data.queue.failed ?? 0) > 0 }">{{ data.queue.failed }}</div>
              <div class="queue-label">失败</div>
            </div>
          </div>
        </el-card>
      </el-col>

      <el-col :span="16">
        <el-card shadow="never" class="metric-card">
          <template #header>近 1 小时接口耗时</template>
          <div v-if="data.api_stats.error" class="text-muted">{{ data.api_stats.error }}</div>
          <template v-else-if="data.api_stats.total">
            <div class="api-summary">
              <span>写操作 <b>{{ data.api_stats.total }}</b> 次</span>
              <span>平均 <b>{{ data.api_stats.avg_ms }}ms</b></span>
              <span>P95 <b>{{ data.api_stats.p95_ms }}ms</b></span>
            </div>
            <el-table :data="data.api_stats.slowest" size="small" stripe style="width: 100%">
              <el-table-column prop="path" label="路径" min-width="220" show-overflow-tooltip>
                <template #default="{ row }">
                  <span class="mono">{{ row.path }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="count" label="次数" width="80" />
              <el-table-column prop="avg_ms" label="平均(ms)" width="100">
                <template #default="{ row }">
                  <span :class="{ 'slow': row.avg_ms > 1000 }">{{ row.avg_ms }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="max_ms" label="最大(ms)" width="100" />
            </el-table>
          </template>
          <el-empty v-else description="近 1 小时无写操作" :image-size="60" />
        </el-card>
      </el-col>
    </el-row>

    <el-card v-if="data" shadow="never" class="metric-card" style="margin-top: 16px">
      <template #header>
        定时任务（启用 {{ data.crontab.enabled ?? 0 }} 个，失败中 {{ data.crontab.failing ?? 0 }} 个）
      </template>
      <el-table v-if="data.crontab.items?.length" :data="data.crontab.items" size="small" stripe style="width: 100%">
        <el-table-column prop="name" label="任务" min-width="160" />
        <el-table-column prop="rule" label="表达式" width="130">
          <template #default="{ row }">
            <span class="mono">{{ row.rule }}</span>
          </template>
        </el-table-column>
        <el-table-column label="连续失败" width="100">
          <template #default="{ row }">
            <el-tag v-if="row.consecutive_failures > 0" type="danger" size="small">{{ row.consecutive_failures }}</el-tag>
            <span v-else>0</span>
          </template>
        </el-table-column>
        <el-table-column prop="last_run_at" label="最近执行" width="180">
          <template #default="{ row }">
            <span class="mono">{{ shortTime(row.last_run_at) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="next_run_at" label="下次执行" width="180">
          <template #default="{ row }">
            <span class="mono">{{ row.next_run_at ?? '—' }}</span>
          </template>
        </el-table-column>
      </el-table>
      <el-empty v-else description="没有启用的定时任务" :image-size="60" />
    </el-card>

    <el-card v-if="data" shadow="never" class="metric-card" style="margin-top: 16px">
      <template #header>
        最近日志（ERROR / WARNING，最多 20 条）
        <el-button link size="small" style="margin-left: 8px" @click="copyErrors">复制</el-button>
      </template>
      <div v-if="data.recent_errors.length" class="log-box">
        <div v-for="(line, i) in data.recent_errors" :key="i" class="log-line" :class="{ 'log-warn': line.includes('WARNING') }">
          {{ line }}
        </div>
      </div>
      <el-empty v-else description="近期没有错误日志" :image-size="60" />
    </el-card>

    <el-skeleton v-if="!data" :rows="8" animated style="margin-top: 16px" />
  </div>
</template>

<style scoped>
.head-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.collected {
  font-size: 12px;
}

.metric-card {
  border-radius: var(--app-radius);
  height: 100%;
}

.metric-main {
  font-size: 20px;
  font-weight: 700;
  margin-bottom: 10px;
}

.metric-list {
  list-style: none;
  margin: 0;
  padding: 0;
  font-size: 13px;
}

.metric-list li {
  display: flex;
  justify-content: space-between;
  padding: 4px 0;
  border-bottom: 1px dashed var(--el-border-color-lighter);
}

.metric-list li:last-child {
  border-bottom: none;
}

.metric-list span {
  color: var(--el-text-color-secondary);
}

.queue-grid {
  display: flex;
  justify-content: space-around;
  padding: 10px 0;
}

.queue-item {
  text-align: center;
}

.queue-num {
  font-size: 26px;
  font-weight: 700;
}

.queue-label {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-top: 4px;
}

.num-danger {
  color: var(--el-color-danger);
}

.api-summary {
  display: flex;
  gap: 24px;
  margin-bottom: 10px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

.api-summary b {
  color: var(--el-text-color-primary);
}

.log-box {
  max-height: 260px;
  overflow: auto;
  font-family: ui-monospace, monospace;
  font-size: 12px;
  background: var(--el-fill-color-light);
  border-radius: 6px;
  padding: 10px;
}

.log-line {
  white-space: pre-wrap;
  word-break: break-all;
  padding: 2px 0;
  color: var(--el-color-danger);
}

.log-line.log-warn {
  color: var(--el-color-warning);
}

.text-muted {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

code.mono {
  background: var(--el-fill-color-light);
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 12px;
}
</style>
