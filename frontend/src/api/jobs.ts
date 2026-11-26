import { http } from '@/api/http'
import type { JobItem, JobResult, JobType } from '@/types'

/** 任务分页（无任务中心权限的账号自动只看自己的） */
export function fetchJobs(
  query: { page?: number; page_size?: number; status?: number; type?: string } = {},
): Promise<JobResult> {
  return http.get<JobResult>('/jobs', { params: query })
}

/** 进行中的任务数（顶栏角标轮询用，失败静默） */
export function fetchRunningJobs(): Promise<{ count: number }> {
  return http.get<{ count: number }>('/jobs/running', { silent: true })
}

/** 创建异步导出任务：type 决定导什么，filters 为该模块的筛选条件 */
export function createExportJob(
  type: JobType,
  filters: Record<string, unknown> = {},
): Promise<JobItem> {
  return http.post<JobItem>('/jobs/export', { type, filters })
}

export function retryJob(id: number): Promise<JobItem> {
  return http.post<JobItem>(`/jobs/${id}/retry`)
}

export function deleteJob(id: number): Promise<null> {
  return http.delete<null>(`/jobs/${id}`)
}
