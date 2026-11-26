<script setup lang="ts">
/**
 * 在线会话：查看谁在哪儿登录着，支持单个会话踢下线、按用户全部踢下线。
 *
 * 数据来自后端 Redis 会话表（登录时登记、每次请求续期）。「在线」= 最近 15 分钟内有请求。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { Refresh, Search } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { fetchSessions, kickSession, kickUserSessions } from '@/api/sessions'
import type { SessionItem } from '@/types'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const loading = ref(false)
const items = ref<SessionItem[]>([])
const total = ref(0)
const online = ref(0)

const query = reactive({
  keyword: '',
  only_online: false,
})

const currentUserId = computed(() => auth.user?.id ?? 0)

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchSessions({
      keyword: query.keyword.trim() || undefined,
      only_online: query.only_online ? 1 : 0,
    })
    items.value = result.items
    total.value = result.total
    online.value = result.online
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

function search(): void {
  void load()
}

function reset(): void {
  query.keyword = ''
  query.only_online = false
  void load()
}

async function kick(row: SessionItem): Promise<void> {
  const target = row.is_current ? '当前设备' : `${row.username} 的这个会话`
  try {
    await ElMessageBox.confirm(
      `踢下线后${target}需要重新登录才能继续使用。确定继续吗？`,
      '踢下线',
      { type: 'warning', confirmButtonText: '踢下线', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await kickSession(row.user_id, row.family_id)
    ElMessage.success('该会话已下线')
    if (row.is_current) {
      // 踢掉自己：清理本地登录态并回登录页
      auth.reset()
      window.location.href = '/login'
      return
    }
    await load()
  } catch {
    // 会话可能已被对方主动退出，错误提示由请求层弹出
  }
}

async function kickAll(row: SessionItem): Promise<void> {
  const isSelf = row.user_id === currentUserId.value
  try {
    await ElMessageBox.confirm(
      isSelf
        ? '将踢掉你自己除当前设备外的所有会话，当前设备保持登录。确定继续吗？'
        : `将踢掉「${row.username}」的全部登录会话，对方所有设备都需要重新登录。确定继续吗？`,
      '踢出全部会话',
      { type: 'warning', confirmButtonText: '全部踢下线', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    const result = await kickUserSessions(row.user_id)
    ElMessage.success(`已下线 ${result.kicked} 个会话`)
    await load()
  } catch {
    // 错误提示由请求层统一处理
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>在线会话</h2>
        <p class="sub">
          最近 15 分钟有请求的会话算「在线」。踢下线会吊销该次登录的整条凭证链，对方下一次请求即失效。
        </p>
      </div>
      <div class="header-actions">
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
      </div>
    </div>

    <el-card shadow="never" class="filter-card">
      <div class="filters">
        <el-input
          v-model="query.keyword"
          placeholder="用户名 / 昵称"
          clearable
          class="w-220"
          @keyup.enter="search"
        />
        <el-checkbox v-model="query.only_online" @change="search">只看在线</el-checkbox>
        <el-button type="primary" @click="search">
          <el-icon><Search /></el-icon>
          <span>查询</span>
        </el-button>
        <el-button @click="reset">重置</el-button>
        <span class="summary">
          共 <b>{{ total }}</b> 个会话，其中 <b class="online">{{ online }}</b> 个在线
        </span>
      </div>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table v-loading="loading" :data="items" stripe style="width: 100%">
        <el-table-column label="用户" min-width="170">
          <template #default="{ row }">
            <div class="user-cell">
              <span class="username mono">{{ row.username }}</span>
              <span class="nickname">{{ row.nickname || '—' }}</span>
              <el-tag v-if="row.is_current" size="small" type="success" effect="plain">当前设备</el-tag>
              <el-tag v-if="row.user_status === 0" size="small" type="danger" effect="plain">已停用</el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="row.online ? 'success' : 'info'" effect="light" size="small">
              {{ row.online ? '在线' : '空闲' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="设备" width="150">
          <template #default="{ row }">
            <span>{{ row.browser }}</span>
            <span class="text-muted"> · {{ row.os }}</span>
          </template>
        </el-table-column>
        <el-table-column label="IP" width="140">
          <template #default="{ row }">
            <span class="mono muted-sm">{{ row.ip || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="登录时间" width="170">
          <template #default="{ row }">
            <span class="mono muted-sm">{{ row.login_at || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="最后活跃" width="170">
          <template #default="{ row }">
            <span class="mono muted-sm">{{ row.last_seen || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="190" fixed="right">
          <template #default="{ row }">
            <template v-if="auth.hasPermission('session.kick')">
              <el-button link type="danger" @click="kick(row)">踢下线</el-button>
              <el-button link type="warning" @click="kickAll(row)">
                {{ row.user_id === currentUserId ? '踢其他设备' : '踢用户全部' }}
              </el-button>
            </template>
            <span v-else class="text-muted">—</span>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="当前没有活跃会话" :image-size="80" />
        </template>
      </el-table>
    </el-card>
  </div>
</template>

<style scoped>
.header-actions {
  display: flex;
  gap: 10px;
}

.filter-card,
.table-card {
  border-radius: var(--app-radius);
}

.filter-card {
  margin-bottom: 14px;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
}

.w-220 {
  width: 220px;
}

.summary {
  margin-left: auto;
  font-size: 12.5px;
  color: var(--app-muted);
}

.summary b {
  color: var(--app-text);
}

.summary b.online {
  color: #16a34a;
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.username {
  font-size: 13px;
  font-weight: 600;
}

.nickname {
  color: var(--app-muted);
  font-size: 12.5px;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.muted-sm {
  font-size: 12px;
  color: var(--app-muted);
}
</style>
