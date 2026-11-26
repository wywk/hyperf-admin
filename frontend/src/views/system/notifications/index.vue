<script setup lang="ts">
/**
 * 消息中心：定时任务失败、密码被重置、账号被停用等事件的站内通知。
 *
 * 消息是「一人一条」的，接口只返回当前登录用户自己的数据，因此本页不需要权限码。
 */
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Check, Delete, Refresh } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import {
  clearReadNotifications,
  deleteNotification,
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from '@/api/notifications'
import { useNotificationStore } from '@/stores/notifications'
import type { NotificationItem, NotificationType } from '@/types'

const router = useRouter()
const store = useNotificationStore()

const loading = ref(false)
const items = ref<NotificationItem[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(10)

const query = reactive<{ is_read: '' | 0 | 1; type: '' | NotificationType }>({
  is_read: '',
  type: '',
})

const TYPE_OPTIONS: Array<{ value: NotificationType; label: string }> = [
  { value: 'system', label: '系统' },
  { value: 'crontab', label: '定时任务' },
  { value: 'account', label: '账号' },
  { value: 'security', label: '安全' },
]

const unreadCount = computed(() => store.unread)

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchNotifications({
      page: page.value,
      page_size: pageSize.value,
      is_read: query.is_read === '' ? undefined : query.is_read,
      type: query.type || undefined,
    })
    items.value = result.items
    total.value = result.total
    store.unread = result.unread
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

function filterChanged(): void {
  page.value = 1
  void load()
}

function resetFilters(): void {
  query.is_read = ''
  query.type = ''
  filterChanged()
}

async function markRead(row: NotificationItem): Promise<void> {
  if (row.is_read) {
    return
  }

  try {
    await markNotificationRead(row.id)
    row.is_read = true
    store.decrease(1)
  } catch {
    // 已删或已读，忽略
  }
}

async function open(row: NotificationItem): Promise<void> {
  await markRead(row)
  if (row.link) {
    await router.push(row.link)
  }
}

async function markAll(): Promise<void> {
  if (unreadCount.value === 0) {
    ElMessage.info('没有未读消息')
    return
  }

  try {
    const result = await markAllNotificationsRead()
    ElMessage.success(`已标记 ${result.updated} 条为已读`)
    await Promise.all([load(), store.refresh()])
  } catch {
    // 请求层已提示
  }
}

async function remove(row: NotificationItem): Promise<void> {
  try {
    await ElMessageBox.confirm(`确定删除消息「${row.title}」吗？`, '删除消息', {
      type: 'warning',
      confirmButtonText: '删除',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  try {
    await deleteNotification(row.id)
    ElMessage.success('消息已删除')
    await Promise.all([load(), store.refresh()])
  } catch {
    // 请求层已提示
  }
}

async function clearRead(): Promise<void> {
  try {
    await ElMessageBox.confirm('将删除所有已读消息，未读消息不受影响。确定继续吗？', '清空已读', {
      type: 'warning',
      confirmButtonText: '清空',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  try {
    const result = await clearReadNotifications()
    ElMessage.success(`已清理 ${result.deleted} 条已读消息`)
    page.value = 1
    await Promise.all([load(), store.refresh()])
  } catch {
    // 请求层已提示
  }
}

const LEVEL_CLASS: Record<string, string> = {
  info: 'lv-info',
  success: 'lv-success',
  warning: 'lv-warning',
  error: 'lv-error',
}

const LEVEL_TEXT: Record<string, string> = {
  info: '通知',
  success: '完成',
  warning: '提醒',
  error: '告警',
}

/** 简单相对时间：列表里看「多久前」比精确时间戳更好扫 */
function fromNow(value: string | null): string {
  if (!value) {
    return ''
  }
  const time = new Date(value.replace(' ', 'T')).getTime()
  if (Number.isNaN(time)) {
    return value
  }

  const diff = Math.floor((Date.now() - time) / 1000)
  if (diff < 60) return '刚刚'
  if (diff < 3600) return `${Math.floor(diff / 60)} 分钟前`
  if (diff < 86400) return `${Math.floor(diff / 3600)} 小时前`
  if (diff < 86400 * 7) return `${Math.floor(diff / 86400)} 天前`
  return value.slice(0, 16)
}

onMounted(() => {
  void load()
  void store.refresh()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>
          消息中心
          <el-tag v-if="unreadCount > 0" type="danger" effect="dark" round size="small">
            {{ unreadCount }} 条未读
          </el-tag>
        </h2>
        <p class="sub">
          任务失败、密码重置、账号停用等事件会自动推送到这里；右上角铃铛保持同步。
        </p>
      </div>
      <div class="header-actions">
        <el-button :disabled="unreadCount === 0" @click="markAll">
          <el-icon><Check /></el-icon>
          <span>全部已读</span>
        </el-button>
        <el-button @click="clearRead">
          <el-icon><Delete /></el-icon>
          <span>清空已读</span>
        </el-button>
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
      </div>
    </div>

    <el-card shadow="never" class="filter-card">
      <div class="filters">
        <el-radio-group v-model="query.is_read" @change="filterChanged">
          <el-radio-button :value="''">全部</el-radio-button>
          <el-radio-button :value="0">未读</el-radio-button>
          <el-radio-button :value="1">已读</el-radio-button>
        </el-radio-group>
        <el-select v-model="query.type" placeholder="全部类型" clearable class="w-160" @change="filterChanged">
          <el-option v-for="item in TYPE_OPTIONS" :key="item.value" :label="item.label" :value="item.value" />
        </el-select>
        <el-button @click="resetFilters">重置</el-button>
        <span class="summary">共 <b>{{ total }}</b> 条</span>
      </div>
    </el-card>

    <el-card v-loading="loading" shadow="never" class="list-card">
      <div v-if="items.length === 0" class="empty">
        <el-empty description="这里还很安静，暂无消息" :image-size="90" />
      </div>

      <ul v-else class="msg-list">
        <li
          v-for="item in items"
          :key="item.id"
          class="msg"
          :class="[{ unread: !item.is_read }, LEVEL_CLASS[item.level] || 'lv-info']"
          @click="open(item)"
        >
          <span class="dot" />
          <div class="msg-body">
            <div class="msg-head">
              <span class="title">{{ item.title }}</span>
              <el-tag size="small" effect="plain" class="type-tag">{{ item.type_text }}</el-tag>
              <el-tag size="small" effect="plain" class="level-tag" :class="LEVEL_CLASS[item.level]">
                {{ LEVEL_TEXT[item.level] || item.level }}
              </el-tag>
              <span class="time">{{ fromNow(item.created_at) }}</span>
            </div>
            <p v-if="item.content" class="content">{{ item.content }}</p>
          </div>
          <div class="msg-actions" @click.stop>
            <el-button v-if="!item.is_read" link type="primary" @click="markRead(item)">标为已读</el-button>
            <el-button link type="danger" @click="remove(item)">删除</el-button>
          </div>
        </li>
      </ul>

      <div v-if="total > pageSize" class="pager">
        <el-pagination
          v-model:current-page="page"
          :page-size="pageSize"
          :total="total"
          layout="prev, pager, next, total"
          background
          @current-change="load"
        />
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
}

.page-header h2 {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 6px;
  font-size: 20px;
}

.sub {
  margin: 0;
  font-size: 12.5px;
  color: var(--app-muted);
}

.header-actions {
  display: flex;
  gap: 10px;
  flex-shrink: 0;
}

.filter-card,
.list-card {
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

.w-160 {
  width: 160px;
}

.summary {
  margin-left: auto;
  font-size: 12.5px;
  color: var(--app-muted);
}

.summary b {
  color: var(--app-text);
}

.msg-list {
  margin: 0;
  padding: 0;
  list-style: none;
}

.msg {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 14px 6px;
  border-bottom: 1px dashed var(--el-border-color-lighter);
  cursor: pointer;
  transition: background 0.15s;
}

.msg:last-child {
  border-bottom: none;
}

.msg:hover {
  background: var(--el-fill-color-light);
}

.dot {
  width: 8px;
  height: 8px;
  margin-top: 7px;
  border-radius: 50%;
  background: var(--el-text-color-placeholder);
  flex-shrink: 0;
}

.msg.unread .dot {
  background: var(--el-color-primary);
  box-shadow: 0 0 0 3px var(--el-color-primary-light-8);
}

.lv-error .dot {
  background: var(--el-color-danger);
}

.lv-warning .dot {
  background: var(--el-color-warning);
}

.lv-success .dot {
  background: var(--el-color-success);
}

.msg-body {
  flex: 1;
  min-width: 0;
}

.msg-head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.title {
  font-size: 14px;
  color: var(--app-muted);
}

.msg.unread .title {
  font-weight: 600;
  color: var(--app-text);
}

.time {
  margin-left: auto;
  font-size: 12px;
  color: var(--app-muted);
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.content {
  margin: 6px 0 0;
  font-size: 13px;
  line-height: 1.6;
  color: var(--app-muted);
  white-space: pre-wrap;
  word-break: break-word;
}

.msg-actions {
  display: flex;
  gap: 4px;
  flex-shrink: 0;
  opacity: 0;
  transition: opacity 0.15s;
}

.msg:hover .msg-actions {
  opacity: 1;
}

.empty {
  padding: 26px 0;
}

.pager {
  display: flex;
  justify-content: flex-end;
  padding-top: 12px;
}
</style>
