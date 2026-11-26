import { http } from '@/api/http'
import type { CaptchaChallenge, LoginPayload, LoginResponse, LoginResult, UserProfile } from '@/types'

export function loginApi(payload: LoginPayload): Promise<LoginResponse> {
  // 登录失败的错误提示交给页面（错误信息更贴近表单），这里不弹全局 toast
  return http.post<LoginResponse>('/auth/login', payload, { silent: true })
}

/** 两步验证第二步：一次性凭证 + 动态码换正式 token */
export function verifyTotpApi(totpToken: string, code: string): Promise<LoginResult> {
  return http.post<LoginResult>('/auth/totp/verify', { totp_token: totpToken, code }, { silent: true })
}

/** 是否需要图形验证码；需要时同时返回图片（SVG data URI） */
export function fetchCaptchaApi(username: string): Promise<CaptchaChallenge> {
  return http.get<CaptchaChallenge>('/auth/captcha', { params: { username }, silent: true })
}

export function setupTotpApi(): Promise<{ secret: string; otpauth_url: string; issuer: string }> {
  return http.post<{ secret: string; otpauth_url: string; issuer: string }>('/auth/totp/setup')
}

export function enableTotpApi(code: string): Promise<null> {
  return http.post<null>('/auth/totp/enable', { code })
}

export function disableTotpApi(code: string): Promise<null> {
  return http.post<null>('/auth/totp/disable', { code })
}

export function meApi(): Promise<UserProfile> {
  return http.get<UserProfile>('/auth/me', { silent: true })
}

export function logoutApi(): Promise<null> {
  return http.post<null>('/auth/logout', undefined, { silent: true })
}
