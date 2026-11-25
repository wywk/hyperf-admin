import { http } from '@/api/http'

export interface MallOrderItemItem {
  id: number
  [key: string]: unknown
}

export function fetchMallOrderItemList(params?: Record<string, unknown>) {
  return http.get<{ total: number; page: number; page_size: number; items: MallOrderItemItem[] }>('/mall_order_item', { params })
}

export function fetchMallOrderItem(id: number) {
  return http.get<MallOrderItemItem>(`/mall_order_item/${id}`)
}

export function createMallOrderItem(data: Partial<MallOrderItemItem>) {
  return http.post<{ id: number }>('/mall_order_item', data)
}

export function updateMallOrderItem(id: number, data: Partial<MallOrderItemItem>) {
  return http.put<{ id: number }>(`/mall_order_item/${id}`, data)
}

export function deleteMallOrderItem(id: number) {
  return http.delete<null>(`/mall_order_item/${id}`)
}
