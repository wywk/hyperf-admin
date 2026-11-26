/**
 * 触发浏览器下载一个 Blob（xlsx/csv 等二进制结果）。
 */
export function saveBlob(blob: Blob, filename: string): void {
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename
  link.click()
  URL.revokeObjectURL(link.href)
}
