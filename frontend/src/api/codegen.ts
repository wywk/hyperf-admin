import { http } from '@/api/http'

export interface TableInfo {
  /** 物理表名，带统一前缀（如 ha_products） */
  name: string
  /** 去掉统一前缀的表名（如 products），生成代码时使用 */
  bare: string
  comment: string
  approx_rows: number
  /** 是否符合统一表前缀规范 */
  is_prefixed: boolean
  is_system: boolean
}

export interface ColumnInfo {
  name: string
  data_type: string
  comment: string
  nullable: boolean
  default: string | null
  primary: boolean
  max_length: number | null
}

/** 一行字段配置（生成器的入参） */
export interface FieldConfig {
  name: string
  label: string
  form_type: 'input' | 'textarea' | 'number' | 'select' | 'radio' | 'checkbox' | 'switch' | 'date'
  required: boolean
  list: boolean
  search: boolean
  form: boolean
  options: string[]
}

export interface GenerateConfig {
  table: string
  module: string
  controller?: string
  menu?: { parent_id?: number; name?: string; path?: string; icon?: string; sort?: number }
  fields: FieldConfig[]
}

export function fetchTables(): Promise<{ items: TableInfo[] }> {
  return http.get('/gen/tables')
}

export function fetchColumns(table: string): Promise<{ items: ColumnInfo[] }> {
  return http.get(`/gen/tables/${table}/fields`)
}

export interface GenerateResult {
  files: Record<string, string> | string[]
  permissions: Record<string, number>
}

export function previewCode(config: GenerateConfig): Promise<{ files: Record<string, string> }> {
  return http.post('/gen/preview', config)
}

export function generateCode(config: GenerateConfig): Promise<{ files: string[]; permissions: Record<string, number> }> {
  return http.post('/gen/generate', config)
}
