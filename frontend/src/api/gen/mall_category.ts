import { http } from '@/api/http'

export interface MallCategoryItem {
  id: number
  [key: string]: unknown
}

export function fetchMallCategoryList(params?: Record<string, unknown>) {
  return http.get<{ total: number; page: number; page_size: number; items: MallCategoryItem[] }>('/mall_category', { params })
}

export function fetchMallCategory(id: number) {
  return http.get<MallCategoryItem>(`/mall_category/${id}`)
}

export function createMallCategory(data: Partial<MallCategoryItem>) {
  return http.post<{ id: number }>('/mall_category', data)
}

export function updateMallCategory(id: number, data: Partial<MallCategoryItem>) {
  return http.put<{ id: number }>(`/mall_category/${id}`, data)
}

export function deleteMallCategory(id: number) {
  return http.delete<null>(`/mall_category/${id}`)
}
