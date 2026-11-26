import { http } from '@/api/http'
import type { DepartmentNode, DepartmentPayload } from '@/types'

/** 完整部门树（含每个部门的用户数） */
export function fetchDepartments(): Promise<DepartmentNode[]> {
  return http.get<DepartmentNode[]>('/departments')
}

export function createDepartment(payload: DepartmentPayload): Promise<DepartmentNode> {
  return http.post<DepartmentNode>('/departments', payload)
}

export function updateDepartment(id: number, payload: DepartmentPayload): Promise<DepartmentNode> {
  return http.put<DepartmentNode>(`/departments/${id}`, payload)
}

export function deleteDepartment(id: number): Promise<null> {
  return http.delete<null>(`/departments/${id}`)
}
