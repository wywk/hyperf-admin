import { http } from '@/api/http'
import type { LoginPayload, LoginResult, UserProfile } from '@/types'

export function loginApi(payload: LoginPayload): Promise<LoginResult> {
  // 登录失败的错误提示交给页面（错误信息更贴近表单），这里不弹全局 toast
  return http.post<LoginResult>('/auth/login', payload, { silent: true })
}

export function meApi(): Promise<UserProfile> {
  return http.get<UserProfile>('/auth/me', { silent: true })
}

export function logoutApi(): Promise<null> {
  return http.post<null>('/auth/logout', undefined, { silent: true })
}
