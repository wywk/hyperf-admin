import { http } from '@/api/http'

export interface MallGoodsItem {
  id: number
  [key: string]: unknown
}

export function fetchMallGoodsList(params?: Record<string, unknown>) {
  return http.get<{ total: number; page: number; page_size: number; items: MallGoodsItem[] }>('/mall_goods', { params })
}

export function fetchMallGoods(id: number) {
  return http.get<MallGoodsItem>(`/mall_goods/${id}`)
}

export function createMallGoods(data: Partial<MallGoodsItem>) {
  return http.post<{ id: number }>('/mall_goods', data)
}

export function updateMallGoods(id: number, data: Partial<MallGoodsItem>) {
  return http.put<{ id: number }>(`/mall_goods/${id}`, data)
}

export function deleteMallGoods(id: number) {
  return http.delete<null>(`/mall_goods/${id}`)
}
