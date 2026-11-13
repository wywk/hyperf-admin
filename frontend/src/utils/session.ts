/**
 * 全局 401 处理钩子：由 main.ts 注册，避免 http 层直接依赖 router / store 造成循环引用。
 */
type UnauthorizedHandler = () => void

let handler: UnauthorizedHandler | null = null

export function onUnauthorized(fn: UnauthorizedHandler): void {
  handler = fn
}

export function emitUnauthorized(): void {
  handler?.()
}
