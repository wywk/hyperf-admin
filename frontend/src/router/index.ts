import { createRouter, createWebHistory } from 'vue-router'
import { routes } from '@/router/routes'
import { useAuthStore } from '@/stores/auth'

const APP_TITLE = import.meta.env.VITE_APP_TITLE || 'Hyperf Admin'

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach(async (to) => {
  document.title = to.meta.title ? `${to.meta.title} · ${APP_TITLE}` : APP_TITLE

  const auth = useAuthStore()

  if (to.meta.public) {
    // 已登录时不再进登录页
    if (to.name === 'login' && auth.isLoggedIn) {
      return { path: '/' }
    }
    return true
  }

  if (!auth.isLoggedIn) {
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }

  // 首次进入或刷新页面时，用户信息还在内存之外，先补齐（同时验证 token 是否仍有效）
  if (!auth.user) {
    try {
      await auth.fetchProfile()
    } catch {
      auth.reset()
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }

  if (to.meta.permission && !auth.hasPermission(to.meta.permission)) {
    return { name: 'forbidden' }
  }

  return true
})

export default router
