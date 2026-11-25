import { http } from '@/api/http'

export interface DictType {
  id: number
  name: string
  code: string
  description: string
  sort: number
  status: number
  item_count: number
  created_at: string | null
}

export interface DictItem {
  id: number
  type_id: number
  label: string
  value: string
  sort: number
  status: number
  remark: string
}

export type DictEntry = { label: string; value: string }

// ---------- 字典类型（管理） ----------

export function fetchDictTypes(keyword?: string): Promise<{ items: DictType[] }> {
  return http.get<{ items: DictType[] }>('/dict/types', {
    params: keyword ? { keyword } : undefined,
  })
}

export function createDictType(data: Partial<DictType>): Promise<DictType> {
  return http.post<DictType>('/dict/types', data)
}

export function updateDictType(id: number, data: Partial<DictType>): Promise<DictType> {
  return http.put<DictType>(`/dict/types/${id}`, data)
}

export function removeDictType(id: number): Promise<{ id: number }> {
  return http.delete(`/dict/types/${id}`)
}

// ---------- 字典项（管理） ----------

export function fetchDictItems(typeId: number): Promise<{ items: DictItem[] }> {
  return http.get<{ items: DictItem[] }>(`/dict/types/${typeId}/items`)
}

export function createDictItem(typeId: number, data: Partial<DictItem>): Promise<DictItem> {
  return http.post<DictItem>(`/dict/types/${typeId}/items`, data)
}

export function updateDictItem(id: number, data: Partial<DictItem>): Promise<DictItem> {
  return http.put<DictItem>(`/dict/items/${id}`, data)
}

export function removeDictItem(id: number): Promise<{ id: number }> {
  return http.delete(`/dict/items/${id}`)
}

// ---------- 业务侧读取（下拉数据源） ----------

export function fetchDict(code: string): Promise<{ items: DictEntry[] }> {
  return http.get<{ items: DictEntry[] }>(`/dict/${code}`)
}

export function fetchDictBatch(codes: string[]): Promise<Record<string, DictEntry[]>> {
  return http.post<Record<string, DictEntry[]>>('/dict/batch', { codes })
}
