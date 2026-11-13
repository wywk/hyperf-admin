import { http } from '@/api/http'
import type { FormDefinition, FormPayload, FormSubmissionItem } from '@/types'

/** 查询参数（分页/过滤） */
export type Query = Record<string, unknown>

/** 表单列表（不含 schema，详情接口才有） */
export function fetchForms(params?: Query): Promise<PageLike> {
  return http.get<PageLike>('/forms', { params })
}

interface PageLike {
  total: number
  page: number
  page_size: number
  items: FormDefinition[]
}

export function fetchForm(id: number): Promise<FormDefinition> {
  return http.get<FormDefinition>(`/forms/${id}`)
}

export function createForm(payload: FormPayload): Promise<{ id: number }> {
  return http.post<{ id: number }>('/forms', payload)
}

export function updateForm(id: number, payload: FormPayload): Promise<{ id: number }> {
  return http.put<{ id: number }>(`/forms/${id}`, payload)
}

export function deleteForm(id: number): Promise<null> {
  return http.delete<null>(`/forms/${id}`)
}

export interface SubmissionsResult {
  form: { id: number; name: string; code: string; schema: FormDefinition['schema'] }
  total: number
  page: number
  page_size: number
  items: FormSubmissionItem[]
}

export function fetchSubmissions(id: number, params?: Query): Promise<SubmissionsResult> {
  return http.get<SubmissionsResult>(`/forms/${id}/submissions`, { params })
}

/** 公开接口：按标识取已发布 Schema（不需要登录） */
export function fetchPublicForm(code: string): Promise<{
  id: number
  name: string
  description: string
  schema: FormDefinition['schema']
}> {
  return http.get(`/form/${code}`)
}
