import type { TokenPair } from '@/types'

const ACCESS_TOKEN_KEY = 'hf_access_token'
const REFRESH_TOKEN_KEY = 'hf_refresh_token'
const EXPIRES_AT_KEY = 'hf_token_expires_at'

/**
 * token 统一放 localStorage：刷新页面、多个标签页都能共享同一登录态。
 * expires_at 存本地时间戳（毫秒），由 expires_in 推算，避免解析后端时区字符串。
 *
 * localStorage 本身不是响应式的，所以这里额外提供订阅：
 * store 里的登录态必须跟着 token 变化走，否则 computed 会一直用旧值缓存
 * （典型表现：登录接口成功了，但路由守卫仍认为未登录，页面跳不过去）。
 */
type TokenListener = (accessToken: string | null) => void

const listeners = new Set<TokenListener>()

export function onTokenChange(listener: TokenListener): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function notify(): void {
  const token = getAccessToken()
  for (const listener of listeners) {
    listener(token)
  }
}

/** 多标签页场景：其他标签退出登录后，本标签也能同步掉登录态 */
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.key === null || event.key === ACCESS_TOKEN_KEY) {
      notify()
    }
  })
}

export function getAccessToken(): string | null {
  return localStorage.getItem(ACCESS_TOKEN_KEY)
}

export function getRefreshToken(): string | null {
  return localStorage.getItem(REFRESH_TOKEN_KEY)
}

export function getExpiresAt(): number | null {
  const raw = localStorage.getItem(EXPIRES_AT_KEY)
  const value = raw ? Number(raw) : Number.NaN
  return Number.isFinite(value) ? value : null
}

export function setTokens(tokens: TokenPair): void {
  localStorage.setItem(ACCESS_TOKEN_KEY, tokens.access_token)
  localStorage.setItem(REFRESH_TOKEN_KEY, tokens.refresh_token)
  localStorage.setItem(EXPIRES_AT_KEY, String(Date.now() + tokens.expires_in * 1000))
  notify()
}

export function clearTokens(): void {
  localStorage.removeItem(ACCESS_TOKEN_KEY)
  localStorage.removeItem(REFRESH_TOKEN_KEY)
  localStorage.removeItem(EXPIRES_AT_KEY)
  notify()
}
