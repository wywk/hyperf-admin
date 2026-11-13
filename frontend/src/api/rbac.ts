import { http } from '@/api/http'
import type {
  PermissionNode,
  PermissionPayload,
  PermissionTreeResult,
  RoleItem,
  RolePayload,
} from '@/types'

/* ---------------- 权限树 ---------------- */

/**
 * 全量权限树（菜单 + 按钮）。keyword 为空时返回整棵树（带 children）；
 * 有关键字时返回平铺的过滤结果，用于权限页搜索。
 */
export function fetchPermissionTree(keyword?: string): Promise<PermissionTreeResult> {
  return http.get<PermissionTreeResult>('/permissions', {
    params: keyword ? { keyword } : {},
  })
}

export function createPermission(payload: PermissionPayload): Promise<PermissionNode> {
  return http.post<PermissionNode>('/permissions', payload)
}

export function updatePermission(id: number, payload: PermissionPayload): Promise<PermissionNode> {
  return http.put<PermissionNode>(`/permissions/${id}`, payload)
}

export function deletePermission(id: number): Promise<null> {
  return http.delete<null>(`/permissions/${id}`)
}

/* ---------------- 角色 ---------------- */

export function fetchRoles(): Promise<{ total: number; items: RoleItem[] }> {
  return http.get<{ total: number; items: RoleItem[] }>('/roles')
}

export function createRole(payload: RolePayload): Promise<RoleItem> {
  return http.post<RoleItem>('/roles', payload)
}

export function updateRole(id: number, payload: RolePayload): Promise<RoleItem> {
  return http.put<RoleItem>(`/roles/${id}`, payload)
}

export function deleteRole(id: number): Promise<null> {
  return http.delete<null>(`/roles/${id}`)
}

export function fetchRolePermissions(id: number): Promise<{ permission_ids: number[] }> {
  return http.get<{ permission_ids: number[] }>(`/roles/${id}/permissions`)
}

export function syncRolePermissions(id: number, permissionIds: number[]): Promise<{ permission_ids: number[] }> {
  return http.put<{ permission_ids: number[] }>(`/roles/${id}/permissions`, { permission_ids: permissionIds })
}
