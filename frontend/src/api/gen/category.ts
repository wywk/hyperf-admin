import { downloadFile, http } from '@/api/http'

export interface CategoryItem {
  id: number
  [key: string]: unknown
}

export function fetchCategoryList(params?: Record<string, unknown>) {
  return http.get<{ total: number; page: number; page_size: number; items: CategoryItem[] }>('/category', { params })
}

export function fetchCategory(id: number) {
  return http.get<CategoryItem>(`/category/${id}`)
}

export function createCategory(data: Partial<CategoryItem>) {
  return http.post<{ id: number }>('/category', data)
}

export function updateCategory(id: number, data: Partial<CategoryItem>) {
  return http.put<{ id: number }>(`/category/${id}`, data)
}

export function deleteCategory(id: number) {
  return http.delete<null>(`/category/${id}`)
}

/** 批量删除，返回实际删除数量 */
export function batchDeleteCategory(ids: number[]) {
  return http.post<{ deleted: number }>(`/category/batch-delete`, { ids })
}

/** 导出 Excel（xlsx Blob），与列表共用搜索参数 */
export function exportCategoryExcel(params?: Record<string, unknown>): Promise<Blob> {
  return downloadFile('/category/export', params)
}

/** 导入 Excel：返回总数 / 成功数 / 失败行明细 */
export interface CategoryImportResult {
  total: number
  success: number
  failed: Array<{ row: number; errors: string[] }>
}

export function importCategoryExcel(file: File): Promise<CategoryImportResult> {
  const form = new FormData()
  form.append('file', file, file.name)
  return http.post<CategoryImportResult>('/category/import', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}
