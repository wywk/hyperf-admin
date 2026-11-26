import { http } from '@/api/http'
import type { NotificationResult, NotificationSummary } from '@/types'

/** 我的消息分页列表 */
export function fetchNotifications(
  query: { page?: number; page_size?: number; is_read?: 0 | 1; type?: string } = {},
): Promise<NotificationResult> {
  return http.get<NotificationResult>('/notifications', { params: query })
}

/** 未读汇总（顶栏铃铛轮询用，失败静默） */
export function fetchNotificationSummary(): Promise<NotificationSummary> {
  return http.get<NotificationSummary>('/notifications/summary', { silent: true })
}

export function markNotificationRead(id: number): Promise<null> {
  return http.post<null>(`/notifications/${id}/read`)
}

export function markAllNotificationsRead(): Promise<{ updated: number }> {
  return http.post<{ updated: number }>('/notifications/read-all')
}

export function deleteNotification(id: number): Promise<null> {
  return http.delete<null>(`/notifications/${id}`)
}

/** 清空已读消息（未读不动） */
export function clearReadNotifications(): Promise<{ deleted: number }> {
  return http.delete<{ deleted: number }>('/notifications/read')
}
