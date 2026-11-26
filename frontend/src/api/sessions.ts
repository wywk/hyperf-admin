import { http } from '@/api/http'
import type { SessionResult } from '@/types'

/** 在线会话列表（只有最近 15 分钟有请求的才算「在线」） */
export function fetchSessions(query: { keyword?: string; only_online?: number } = {}): Promise<SessionResult> {
  return http.get<SessionResult>('/sessions', { params: query })
}

/** 踢掉单个会话（吊销整条凭证链） */
export function kickSession(userId: number, familyId: string): Promise<null> {
  return http.delete<null>(`/sessions/${userId}/${familyId}`)
}

/** 踢掉某个用户的全部会话；踢自己时保留当前会话 */
export function kickUserSessions(userId: number): Promise<{ kicked: number }> {
  return http.delete<{ kicked: number }>(`/sessions/user/${userId}`)
}
