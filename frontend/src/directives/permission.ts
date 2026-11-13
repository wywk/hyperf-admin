import type { Directive, DirectiveBinding } from 'vue'
import { useAuthStore } from '@/stores/auth'

type PermissionValue = string | string[]

function check(binding: DirectiveBinding<PermissionValue>): boolean {
  const auth = useAuthStore()
  const codes = Array.isArray(binding.value) ? binding.value : [binding.value]
  // 默认按「满足任意一个」判定，与 hasAnyPermission 保持一致
  return auth.hasAnyPermission(codes)
}

/**
 * 按钮级权限：无权限时直接移除节点。
 * 用法：
 *   <el-button v-permission="'user.manage'">新增用户</el-button>
 *   <el-button v-permission="['permission.edit', 'permission.delete']">任意其一</el-button>
 *
 * 注意：这只是界面控制，后端接口必须自己做权限校验。
 */
export const permissionDirective: Directive<HTMLElement, PermissionValue> = {
  mounted(el, binding) {
    if (!check(binding)) {
      el.parentNode?.removeChild(el)
    }
  },
}
