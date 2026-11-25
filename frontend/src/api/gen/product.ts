import { downloadFile, http } from '@/api/http'

export interface ProductItem {
  id: number
  [key: string]: unknown
}

export function fetchProductList(params?: Record<string, unknown>) {
  return http.get<{ total: number; page: number; page_size: number; items: ProductItem[] }>('/product', { params })
}

export function fetchProduct(id: number) {
  return http.get<ProductItem>(`/product/${id}`)
}

export function createProduct(data: Partial<ProductItem>) {
  return http.post<{ id: number }>('/product', data)
}

export function updateProduct(id: number, data: Partial<ProductItem>) {
  return http.put<{ id: number }>(`/product/${id}`, data)
}

export function deleteProduct(id: number) {
  return http.delete<null>(`/product/${id}`)
}

/** 导出 Excel（xlsx Blob），与列表共用搜索参数 */
export function exportProductExcel(params?: Record<string, unknown>): Promise<Blob> {
  return downloadFile('/product/export', params)
}
