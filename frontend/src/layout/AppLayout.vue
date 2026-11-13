<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { CaretBottom, Document, Expand, Fold } from '@element-plus/icons-vue'
import { useAuthStore } from '@/stores/auth'
import SidebarItem from '@/layout/SidebarItem.vue'
import { API_BASE_URL } from '@/api/http'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const collapsed = ref(window.innerWidth < 960)
const activePath = computed(() => route.path)
const currentTitle = computed(() => route.meta.title ?? '')
const apiBase = API_BASE_URL

/** 菜单树来自后端 /auth/me，按角色过滤，前端不再维护菜单清单 */
const menus = computed(() => auth.menus)

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
          <a class="doc-link" href="/api/docs" target="_blank" rel="noreferrer">
            <el-icon><Document /></el-icon>
            <span>接口文档</span>
          </a>

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
  background: #fff;
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
  background: #f3f4f6;
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
</style>
