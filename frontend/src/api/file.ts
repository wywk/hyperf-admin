import { http } from '@/api/http'
import type { PageResult } from '@/types'

export interface FileItem {
  id: number
  name: string
  path: string
  driver: string
  mime: string
  ext: string
  size: number
  size_text: string
  url: string
  is_image: boolean
  user_id: number
  created_at: string | null
}

export interface FileQuery {
  page?: number
  page_size?: number
  keyword?: string
  ext?: string
  user_id?: number | string
}

export function uploadFile(file: File): Promise<FileItem> {
  const form = new FormData()
  form.append('file', file, file.name)
  return http.post<FileItem>('/files/upload', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

export function fetchFiles(params: FileQuery): Promise<PageResult<FileItem>> {
  return http.get<PageResult<FileItem>>('/files', { params })
}

export function removeFile(id: number): Promise<{ id: number }> {
  return http.delete(`/files/${id}`)
}
