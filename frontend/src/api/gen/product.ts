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

/** 批量删除，返回实际删除数量 */
export function batchDeleteProduct(ids: number[]) {
  return http.post<{ deleted: number }>(`/product/batch-delete`, { ids })
}

/** 导出 Excel（xlsx Blob），与列表共用搜索参数 */
export function exportProductExcel(params?: Record<string, unknown>): Promise<Blob> {
  return downloadFile('/product/export', params)
}

/** 导入 Excel：返回总数 / 成功数 / 失败行明细 */
export interface ProductImportResult {
  total: number
  success: number
  failed: Array<{ row: number; errors: string[] }>
}

export function importProductExcel(file: File): Promise<ProductImportResult> {
  const form = new FormData()
  form.append('file', file, file.name)
  return http.post<ProductImportResult>('/product/import', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}
