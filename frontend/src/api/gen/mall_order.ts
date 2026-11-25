import { http } from '@/api/http'

export interface MallOrderItem {
  id: number
  [key: string]: unknown
}

export function fetchMallOrderList(params?: Record<string, unknown>) {
  return http.get<{ total: number; page: number; page_size: number; items: MallOrderItem[] }>('/mall_order', { params })
}

export function fetchMallOrder(id: number) {
  return http.get<MallOrderItem>(`/mall_order/${id}`)
}

export function createMallOrder(data: Partial<MallOrderItem>) {
  return http.post<{ id: number }>('/mall_order', data)
}

export function updateMallOrder(id: number, data: Partial<MallOrderItem>) {
  return http.put<{ id: number }>(`/mall_order/${id}`, data)
}

export function deleteMallOrder(id: number) {
  return http.delete<null>(`/mall_order/${id}`)
}
