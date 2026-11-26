<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { Bell, CaretBottom, Document, Expand, Fold, List, Moon, ShoppingCart, Sunny } from '@element-plus/icons-vue'
import { useTheme } from '@/composables/theme'

const { isDark, toggle: toggleTheme } = useTheme()
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import { useJobStore } from '@/stores/jobs'
import SidebarItem from '@/layout/SidebarItem.vue'
import { API_BASE_URL } from '@/api/http'
import {
  fetchNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from '@/api/notifications'
import type { NotificationItem } from '@/types'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const notify = useNotificationStore()
const jobStore = useJobStore()

const collapsed = ref(window.innerWidth < 960)
const activePath = computed(() => route.path)
const currentTitle = computed(() => route.meta.title ?? '')
const apiBase = API_BASE_URL

/** 菜单树来自后端 /auth/me，按角色过滤，前端不再维护菜单清单 */
const menus = computed(() => auth.menus)

// ---------- 顶栏消息：未读数 60 秒轮询一次，打开面板时拉最近 8 条 ----------
const bellVisible = ref(false)
const recent = ref<NotificationItem[]>([])
const recentLoading = ref(false)
let pollTimer: number | undefined
let jobPollTimer: number | undefined

async function loadRecent(): Promise<void> {
  recentLoading.value = true
  try {
    const result = await fetchNotifications({ page: 1, page_size: 8 })
    recent.value = result.items
    notify.unread = result.unread
  } catch {
    // 静默：面板打开失败不打扰用户
  } finally {
    recentLoading.value = false
  }
}

async function openNotification(item: NotificationItem): Promise<void> {
  if (!item.is_read) {
    try {
      await markNotificationRead(item.id)
      item.is_read = true
      notify.decrease(1)
    } catch {
      // 已读状态以服务端为准，失败不阻塞跳转
    }
  }

  bellVisible.value = false
  if (item.link) {
    await router.push(item.link)
  } else {
    await router.push({ name: 'notifications' })
  }
}

async function readAllNotifications(): Promise<void> {
  if (!notify.hasUnread) {
    return
  }

  try {
    const result = await markAllNotificationsRead()
    ElMessage.success(`已标记 ${result.updated} 条为已读`)
    await Promise.all([notify.refresh(), loadRecent()])
  } catch {
    // 请求层已提示
  }
}

function goAllNotifications(): void {
  bellVisible.value = false
  void router.push({ name: 'notifications' })
}

function timeAgo(value: string | null): string {
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
  return `${Math.floor(diff / 86400)} 天前`
}

watch(bellVisible, (visible) => {
  if (visible) {
    void loadRecent()
  }
})

onMounted(() => {
  void notify.refresh()
  pollTimer = window.setInterval(() => void notify.refresh(), 60000)

  // 顶栏任务角标：进行中的异步任务数，30 秒轮询
  void jobStore.refresh()
  jobPollTimer = window.setInterval(() => void jobStore.refresh(), 30000)
})

onBeforeUnmount(() => {
  if (pollTimer) {
    window.clearInterval(pollTimer)
  }
  if (jobPollTimer) {
    window.clearInterval(jobPollTimer)
  }
})

async function onCommand(command: string): Promise<void> {
  if (command === 'profile') {
    await router.push({ name: 'profile' })
    return
  }

  if (command === 'logout') {
    try {
      await ElMessageBox.confirm('退出后需要重新登录，确定继续吗？', '退出登录', {
        type: 'warning',
        confirmButtonText: '退出',
        cancelButtonText: '取消',
      })
    } catch {
      return
    }

    await auth.logout()
    notify.reset()
    ElMessage.success('已退出登录')
    await router.replace({ name: 'login' })
  }
}
</script>

<template>
  <el-container class="layout">
    <el-aside :width="collapsed ? '64px' : '220px'" class="aside">
      <div class="brand">
        <div class="logo">H</div>
        <div v-show="!collapsed" class="brand-text">
          <strong>Hyperf Admin</strong>
          <span>Vue 3 · Element Plus</span>
        </div>
      </div>

      <el-menu
        :default-active="activePath"
        :collapse="collapsed"
        :collapse-transition="false"
        router
        class="menu"
      >
        <SidebarItem v-for="item in menus" :key="item.id" :node="item" />
      </el-menu>

      <div v-show="!collapsed" class="aside-foot">
        <span class="dot" />
        <span class="mono">{{ apiBase }}</span>
      </div>
    </el-aside>

    <el-container class="body">
      <el-header class="header" height="56px">
        <div class="header-left">
          <el-button text class="collapse-btn" @click="collapsed = !collapsed">
            <el-icon :size="18">
              <Expand v-if="collapsed" />
              <Fold v-else />
            </el-icon>
          </el-button>
          <el-breadcrumb separator="/">
            <el-breadcrumb-item :to="{ path: '/' }">首页</el-breadcrumb-item>
            <el-breadcrumb-item v-if="currentTitle">{{ currentTitle }}</el-breadcrumb-item>
          </el-breadcrumb>
        </div>

        <div class="header-right">
          <el-tooltip :content="isDark ? '切换到浅色' : '切换到深色'" placement="bottom">
            <el-button text circle class="theme-btn" @click="toggleTheme">
              <el-icon :size="16"><Moon v-if="!isDark" /><Sunny v-else /></el-icon>
            </el-button>
          </el-tooltip>

          <a class="doc-link" href="/shop" target="_blank" rel="noreferrer">
            <el-icon><ShoppingCart /></el-icon>
            <span>手机商城</span>
          </a>

          <a class="doc-link" href="/api/docs" target="_blank" rel="noreferrer">
            <el-icon><Document /></el-icon>
            <span>接口文档</span>
          </a>

          <!-- 异步任务角标：有排队/执行中的任务时展示，点击进任务中心 -->
          <el-badge
            :value="jobStore.badge"
            :hidden="!jobStore.hasRunning"
            class="bell-badge"
            type="warning"
          >
            <el-button text circle class="bell-btn" title="任务中心" @click="router.push({ name: 'jobs' })">
              <el-icon :size="17"><List /></el-icon>
            </el-button>
          </el-badge>

          <el-popover
            v-model:visible="bellVisible"
            trigger="click"
            placement="bottom-end"
            :width="360"
            popper-class="notify-popper"
          >
            <template #reference>
              <el-badge :value="notify.unread" :max="99" :hidden="!notify.hasUnread" class="bell-badge">
                <el-button text circle class="bell-btn" title="通知">
                  <el-icon :size="17"><Bell /></el-icon>
                </el-button>
              </el-badge>
            </template>

            <div class="notify-panel">
              <div class="notify-head">
                <span>通知</span>
                <div>
                  <el-button link type="primary" size="small" :disabled="!notify.hasUnread" @click="readAllNotifications">
                    全部已读
                  </el-button>
                  <el-button link size="small" @click="goAllNotifications">查看全部</el-button>
                </div>
              </div>

              <div v-if="recent.length === 0" class="notify-empty">
                {{ recentLoading ? '加载中…' : '暂无消息' }}
              </div>
              <ul v-else class="notify-list">
                <li
                  v-for="item in recent"
                  :key="item.id"
                  :class="{ unread: !item.is_read }"
                  @click="openNotification(item)"
                >
                  <span class="nd" :class="'lv-' + item.level" />
                  <div class="nc">
                    <div class="nt">{{ item.title }}</div>
                    <div class="nx">{{ item.content }}</div>
                  </div>
                  <span class="ntime">{{ timeAgo(item.created_at) }}</span>
                </li>
              </ul>
            </div>
          </el-popover>

          <el-dropdown trigger="click" @command="onCommand">
            <div class="user">
              <el-avatar :size="28" class="avatar">{{ auth.avatarText }}</el-avatar>
              <span class="user-name">{{ auth.displayName }}</span>
              <el-icon :size="12"><CaretBottom /></el-icon>
            </div>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="profile">个人中心</el-dropdown-item>
                <el-dropdown-item command="logout" divided>退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </el-header>

      <el-main class="main">
        <router-view v-slot="{ Component }">
          <transition name="fade" mode="out-in">
            <component :is="Component" />
          </transition>
        </router-view>
      </el-main>
    </el-container>
  </el-container>
</template>

<style scoped>
.layout {
  height: 100vh;
}

.aside {
  display: flex;
  flex-direction: column;
  background: var(--app-sidebar-bg);
  border-right: 1px solid var(--app-border);
  transition: width 0.2s ease;
  overflow: hidden;
}

.brand {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 56px;
  padding: 0 16px;
  border-bottom: 1px solid var(--app-border);
  white-space: nowrap;
}

.logo {
  width: 32px;
  height: 32px;
  flex: none;
  border-radius: 8px;
  background: linear-gradient(135deg, #2563eb, #4f8cff);
  color: #fff;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.brand-text {
  display: flex;
  flex-direction: column;
  line-height: 1.25;
}

.brand-text strong {
  font-size: 14px;
}

.brand-text span {
  font-size: 11px;
  color: var(--app-muted);
}

.menu {
  flex: 1;
  border-right: none;
  padding-top: 6px;
}

.aside-foot {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 16px;
  border-top: 1px solid var(--app-border);
  font-size: 11px;
  color: var(--app-muted);
  white-space: nowrap;
}

.dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #22c55e;
}

.body {
  min-width: 0;
}

.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--app-sidebar-bg);
  border-bottom: 1px solid var(--app-border);
  padding: 0 16px;
}

.header-left,
.header-right {
  display: flex;
  align-items: center;
  gap: 14px;
}

.collapse-btn {
  padding: 4px;
}

.doc-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 13px;
  color: var(--app-muted);
  transition: color 0.15s;
}

.doc-link:hover {
  color: var(--app-brand);
}

.user {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  padding: 4px 6px;
  border-radius: 8px;
  transition: background 0.15s;
}

.user:hover {
  background: var(--el-fill-color);
}

.avatar {
  background: var(--app-brand);
  color: #fff;
  font-size: 13px;
}

.user-name {
  font-size: 13px;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.main {
  padding: 18px;
  background: var(--app-bg);
  overflow-y: auto;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.16s ease, transform 0.16s ease;
}

.fade-enter-from {
  opacity: 0;
  transform: translateY(6px);
}

.fade-leave-to {
  opacity: 0;
}

/* 铃铛：贴着顶栏按钮的尺寸，未读时轻微高亮 */
.bell-btn {
  padding: 4px;
  color: var(--app-muted);
}

.bell-btn:hover {
  color: var(--app-brand);
}

.bell-badge :deep(.el-badge__content) {
  top: 6px;
  right: 10px;
}
</style>

<style>
/* 弹层被 teleport 到 body，作用域样式够不到，这里用 notify- 前缀做隔离 */
.notify-popper {
  padding: 0 !important;
}

.notify-panel {
  font-size: 13px;
}

.notify-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  border-bottom: 1px solid var(--el-border-color-lighter);
  font-weight: 600;
}

.notify-list {
  max-height: 340px;
  margin: 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
}

.notify-list li {
  display: flex;
  gap: 10px;
  padding: 11px 14px;
  border-bottom: 1px dashed var(--el-border-color-lighter);
  cursor: pointer;
  transition: background 0.15s;
}

.notify-list li:last-child {
  border-bottom: none;
}

.notify-list li:hover {
  background: var(--el-fill-color-light);
}

.notify-list .nd {
  width: 7px;
  height: 7px;
  margin-top: 6px;
  border-radius: 50%;
  background: var(--el-text-color-placeholder);
  flex-shrink: 0;
}

.notify-list li.unread .nd {
  background: var(--el-color-primary);
}

.notify-list .nd.lv-error {
  background: var(--el-color-danger);
}

.notify-list .nd.lv-warning {
  background: var(--el-color-warning);
}

.notify-list .nd.lv-success {
  background: var(--el-color-success);
}

.notify-list .nc {
  flex: 1;
  min-width: 0;
}

.notify-list .nt {
  font-size: 13px;
  color: var(--el-text-color-regular);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notify-list li.unread .nt {
  font-weight: 600;
  color: var(--el-text-color-primary);
}

.notify-list .nx {
  margin-top: 3px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.notify-list .ntime {
  flex-shrink: 0;
  font-size: 11px;
  color: var(--el-text-color-placeholder);
}

.notify-empty {
  padding: 28px 0;
  text-align: center;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
</style>
