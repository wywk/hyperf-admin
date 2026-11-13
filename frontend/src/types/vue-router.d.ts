import 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    /** 页面标题，用于 document.title 与面包屑 */
    title?: string
    /** 无需登录即可访问 */
    public?: boolean
    /** 是否出现在侧边菜单 */
    menu?: boolean
    /** Element Plus 图标组件名 */
    icon?: string
    /** 访问所需权限码，缺失则跳 403 */
    permission?: string
  }
}
