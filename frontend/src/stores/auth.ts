import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { loginApi, logoutApi, meApi } from '@/api/auth'
import type { LoginPayload, LoginResponse, LoginResult, MenuNode, UserProfile } from '@/types'
import { clearTokens, getAccessToken, onTokenChange, setTokens } from '@/utils/token'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<UserProfile | null>(null)
  const submitting = ref(false)

  /**
   * 登录态必须落在响应式数据上：localStorage 的变化不会触发 computed 重算，
   * 直接 computed(() => !!getAccessToken()) 会在第一次取值后永久缓存。
   */
  const accessToken = ref<string | null>(getAccessToken())
  onTokenChange((token) => {
    accessToken.value = token
    if (!token) {
      user.value = null
    }
  })

  const isLoggedIn = computed(() => Boolean(accessToken.value))
  const permissions = computed<string[]>(() => user.value?.permissions ?? [])
  const menus = computed<MenuNode[]>(() => user.value?.menus ?? [])
  const roles = computed<string[]>(() => user.value?.roles ?? [])
  const displayName = computed(() => user.value?.nickname || user.value?.username || '未登录')
  const avatarText = computed(() => displayName.value.slice(0, 1).toUpperCase())

  function hasPermission(code?: string): boolean {
    if (!code) {
      return true
    }
    return permissions.value.includes(code)
  }

  function hasAnyPermission(codes: string[]): boolean {
    if (codes.length === 0) {
      return true
    }
    return codes.some((code) => hasPermission(code))
  }

  /**
   * 登录：开启两步验证的账号会拿到一次性凭证而不写 token，由登录页继续第二步。
   */
  async function login(payload: LoginPayload): Promise<LoginResponse> {
    submitting.value = true
    try {
      const result = await loginApi(payload)
      if ('requires_totp' in result) {
        return result
      }
      completeLogin(result)
      return result
    } finally {
      submitting.value = false
    }
  }

  /** 两步验证通过后写登录态（登录页调用） */
  function completeLogin(result: LoginResult): void {
    setTokens(result)
    user.value = result.user
  }

  async function fetchProfile(force = false): Promise<UserProfile> {
    if (user.value && !force) {
      return user.value
    }
    user.value = await meApi()
    return user.value
  }

  async function logout(): Promise<void> {
    try {
      await logoutApi()
    } catch {
      // 后端不可用或 token 已过期时也要能退出，本地状态照常清理
    } finally {
      reset()
    }
  }

  function reset(): void {
    clearTokens()
    user.value = null
  }

  return {
    user,
    submitting,
    isLoggedIn,
    permissions,
    menus,
    roles,
    displayName,
    avatarText,
    hasPermission,
    hasAnyPermission,
    login,
    completeLogin,
    fetchProfile,
    logout,
    reset,
  }
})
