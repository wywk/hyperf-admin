import { http } from '@/api/http'
import type { LoginLogItem, LoginLogQuery, PageResult } from '@/types'

export function fetchLoginLogs(params: LoginLogQuery): Promise<PageResult<LoginLogItem>> {
  return http.get<PageResult<LoginLogItem>>('/auth/login-logs', { params })
}
