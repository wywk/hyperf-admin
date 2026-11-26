import { http } from '@/api/http'
import type { PageResult, UserItem, UserPayload, UserQuery } from '@/types'

/** 用户分页列表（keyword 匹配用户名/昵称/邮箱） */
export function fetchUsers(query: UserQuery = {}): Promise<PageResult<UserItem>> {
  return http.get<PageResult<UserItem>>('/users', { params: query })
}

export function createUser(payload: UserPayload): Promise<UserItem> {
  return http.post<UserItem>('/users', payload)
}

export function updateUser(id: number, payload: UserPayload): Promise<UserItem> {
  return http.put<UserItem>(`/users/${id}`, payload)
}

/** 启用 / 停用；停用后该用户已登录的会话全部失效 */
export function updateUserStatus(id: number, status: number): Promise<UserItem> {
  return http.put<UserItem>(`/users/${id}/status`, { status })
}

/** 管理员重置密码（无需旧密码），重置后该用户全部会话下线 */
export function resetUserPassword(id: number, password: string): Promise<null> {
  return http.put<null>(`/users/${id}/password`, { password })
}

export function deleteUser(id: number): Promise<null> {
  return http.delete<null>(`/users/${id}`)
}

/** 修改自己的密码，成功后其他设备下线 */
export function changeOwnPassword(oldPassword: string, newPassword: string): Promise<null> {
  return http.post<null>('/auth/password', {
    old_password: oldPassword,
    new_password: newPassword,
  })
}
