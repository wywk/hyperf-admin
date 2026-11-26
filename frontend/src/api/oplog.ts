import { downloadFile, http } from '@/api/http'
import type { OperationLogItem, OperationLogQuery, PageResult } from '@/types'

export function fetchOperationLogs(params: OperationLogQuery): Promise<PageResult<OperationLogItem>> {
  return http.get<PageResult<OperationLogItem>>('/operation-logs', { params })
}

export function exportOperationLogs(params: OperationLogQuery): Promise<Blob> {
  return downloadFile('/operation-logs/export', { ...params })
}
