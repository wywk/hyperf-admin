import { http } from '@/api/http'

export interface TrendItem {
  date: string
  count: number
}

export interface DashboardStats {
  users_total: number
  users_enabled: number
  roles_total: number
  files_total: number
  logins_today: number
  operations_today: number
  operations_total: number
  crontab_enabled: number
  crontab_failing: number
  trend: TrendItem[]
}

export function fetchDashboardStats(): Promise<DashboardStats> {
  return http.get<DashboardStats>('/dashboard/stats')
}
