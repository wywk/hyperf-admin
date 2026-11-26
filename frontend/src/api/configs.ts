import { http } from '@/api/http'
import type { ConfigGroup } from '@/types'

/** 按分组读取系统配置（含 type/name/description，用于渲染动态表单） */
export function fetchConfigs(): Promise<ConfigGroup[]> {
  return http.get<ConfigGroup[]>('/configs')
}

/** 批量保存，只传改动过的键；后端按各配置项的 type 转换后落库 */
export function saveConfigs(items: Record<string, unknown>): Promise<Record<string, unknown>> {
  return http.put<Record<string, unknown>>('/configs', { items })
}
