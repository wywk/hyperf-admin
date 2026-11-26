import { http } from '@/api/http'
import type { CrontabLogItem, CrontabPayload, CrontabTaskItem, PageResult } from '@/types'

export interface CrontabTaskQuery {
  page?: number
  page_size?: number
  name?: string
  status?: number
}

export function fetchCrontabs(params: CrontabTaskQuery): Promise<PageResult<CrontabTaskItem>> {
  return http.get<PageResult<CrontabTaskItem>>('/crontabs', { params })
}

export function createCrontab(payload: CrontabPayload): Promise<CrontabTaskItem> {
  return http.post<CrontabTaskItem>('/crontabs', payload)
}

export function updateCrontab(id: number, payload: CrontabPayload): Promise<CrontabTaskItem> {
  return http.put<CrontabTaskItem>(`/crontabs/${id}`, payload)
}

export function deleteCrontab(id: number): Promise<null> {
  return http.delete<null>(`/crontabs/${id}`)
}

export function runCrontab(id: number): Promise<CrontabLogItem> {
  return http.post<CrontabLogItem>(`/crontabs/${id}/run`)
}

export function fetchCrontabLogs(id: number, params: { page?: number; page_size?: number }): Promise<PageResult<CrontabLogItem>> {
  return http.get<PageResult<CrontabLogItem>>(`/crontabs/${id}/logs`, { params })
}
