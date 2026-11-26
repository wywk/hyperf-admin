import { downloadFile, http } from '@/api/http'
import type { LoginLogItem, LoginLogQuery, PageResult } from '@/types'

export function fetchLoginLogs(params: LoginLogQuery): Promise<PageResult<LoginLogItem>> {
  return http.get<PageResult<LoginLogItem>>('/auth/login-logs', { params })
}

export function exportLoginLogs(params: LoginLogQuery): Promise<Blob> {
  return downloadFile('/auth/login-logs/export', { ...params })
}
