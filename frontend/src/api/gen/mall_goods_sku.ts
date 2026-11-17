import { http } from '@/api/http'

export interface MallGoodsSkuItem {
  id: number
  [key: string]: unknown
}

export function fetchMallGoodsSkuList(params?: Record<string, unknown>) {
  return http.get<{ total: number; page: number; page_size: number; items: MallGoodsSkuItem[] }>('/mall_goods_sku', { params })
}

export function fetchMallGoodsSku(id: number) {
  return http.get<MallGoodsSkuItem>(`/mall_goods_sku/${id}`)
}

export function createMallGoodsSku(data: Partial<MallGoodsSkuItem>) {
  return http.post<{ id: number }>('/mall_goods_sku', data)
}

export function updateMallGoodsSku(id: number, data: Partial<MallGoodsSkuItem>) {
  return http.put<{ id: number }>(`/mall_goods_sku/${id}`, data)
}

export function deleteMallGoodsSku(id: number) {
  return http.delete<null>(`/mall_goods_sku/${id}`)
}
