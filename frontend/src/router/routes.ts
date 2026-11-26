import type { RouteRecordRaw } from 'vue-router'

/**
 * 布局内的页面路由。
 *
 * meta.permission = 对应的「菜单权限码」（permissions 表里 type=menu 的 code），
 * 与后端种子数据保持一致；侧边栏本身由 /auth/me 返回的菜单树驱动，
 * 这里只负责路由 → 组件的映射与守卫判断。
 */
export const layoutRoutes: RouteRecordRaw[] = [
  {
    path: 'dashboard',
    name: 'dashboard',
    component: () => import('@/views/dashboard/index.vue'),
    meta: { title: '仪表盘', permission: 'dashboard' },
  },
  {
    path: 'audit',
    name: 'audit',
    component: () => import('@/views/audit/index.vue'),
    meta: { title: '登录审计', permission: 'audit' },
  },
  {
    // 个人消息：只返回自己的数据，不需要权限码，所有登录用户都能进
    path: 'system/notifications',
    name: 'notifications',
    component: () => import('@/views/system/notifications/index.vue'),
    meta: { title: '消息中心' },
  },
  {
    path: 'system/permissions',
    name: 'permissions',
    component: () => import('@/views/system/permissions/index.vue'),
    meta: { title: '权限管理', permission: 'permission' },
  },
  {
    path: 'system/users',
    name: 'users',
    component: () => import('@/views/system/users/index.vue'),
    meta: { title: '用户管理', permission: 'user' },
  },
  {
    path: 'system/departments',
    name: 'departments',
    component: () => import('@/views/system/departments/index.vue'),
    meta: { title: '部门管理', permission: 'dept' },
  },
  {
    path: 'system/roles',
    name: 'roles',
    component: () => import('@/views/system/roles/index.vue'),
    meta: { title: '角色管理', permission: 'role' },
  },
  {
    path: 'system/sessions',
    name: 'sessions',
    component: () => import('@/views/system/sessions/index.vue'),
    meta: { title: '在线会话', permission: 'session' },
  },
  {
    path: 'system/files',
    name: 'files',
    component: () => import('@/views/system/files/index.vue'),
    meta: { title: '文件管理', permission: 'file' },
  },
  {
    path: 'system/dicts',
    name: 'dicts',
    component: () => import('@/views/system/dict/index.vue'),
    meta: { title: '数据字典', permission: 'dict' },
  },
  {
    path: 'system/configs',
    name: 'configs',
    component: () => import('@/views/system/configs/index.vue'),
    meta: { title: '系统配置', permission: 'config' },
  },
  {
    path: 'system/oplogs',
    name: 'oplogs',
    component: () => import('@/views/system/oplogs/index.vue'),
    meta: { title: '操作日志', permission: 'oplog' },
  },
  {
    path: 'system/jobs',
    name: 'jobs',
    component: () => import('@/views/system/jobs/index.vue'),
    meta: { title: '任务中心' },
  },
  {
    path: 'system/crontabs',
    name: 'crontabs',
    component: () => import('@/views/system/crontabs/index.vue'),
    meta: { title: '定时任务', permission: 'crontab' },
  },
  {
    path: 'tool/forms',
    name: 'forms',
    component: () => import('@/views/tool/forms/index.vue'),
    meta: { title: '表单管理', permission: 'form' },
  },
  {
    // 设计器不是菜单页，从表单管理页跳转
    path: 'tool/forms/designer',
    name: 'form-designer',
    component: () => import('@/views/tool/forms/designer.vue'),
    meta: { title: '表单设计器', permission: 'form' },
  },
  {
    path: 'tool/gen',
    name: 'gen',
    component: () => import('@/views/tool/gen/index.vue'),
    meta: { title: '代码生成', permission: 'gen' },
  },
  {
    // 工具开发是纯目录，落到第一个子页面
    path: 'tool',
    redirect: '/tool/forms',
  },
  {
    path: 'profile',
    name: 'profile',
    component: () => import('@/views/profile/index.vue'),
    meta: { title: '个人中心', permission: 'profile' },
  },
  {
    // 系统管理是纯目录（后端菜单树里没有可点路由），落到第一个子页面
    path: 'system',
    redirect: '/system/permissions',
  },
  {
    path: '403',
    name: 'forbidden',
    component: () => import('@/views/error/403.vue'),
    meta: { title: '无权访问' },
  },
  // [gen:mall_category] start（代码生成器维护，勿手改）
  {
    path: 'mall/category',
    name: 'gen-mall_category',
    component: () => import('@/views/gen/mall_category/index.vue'),
    meta: { title: '分类管理', permission: 'mall_category' },
  },
  // [gen:mall_category] end
  // [gen:mall_goods] start（代码生成器维护，勿手改）
  {
    path: 'mall/goods',
    name: 'gen-mall_goods',
    component: () => import('@/views/gen/mall_goods/index.vue'),
    meta: { title: '商品管理', permission: 'mall_goods' },
  },
  // [gen:mall_goods] end
  // [gen:mall_goods_sku] start（代码生成器维护，勿手改）
  {
    path: 'mall/sku',
    name: 'gen-mall_goods_sku',
    component: () => import('@/views/gen/mall_goods_sku/index.vue'),
    meta: { title: 'SKU管理', permission: 'mall_goods_sku' },
  },
  // [gen:mall_goods_sku] end
  // [gen:mall_order] start（代码生成器维护，勿手改）
  {
    path: 'mall/order',
    name: 'gen-mall_order',
    component: () => import('@/views/gen/mall_order/index.vue'),
    meta: { title: '订单管理', permission: 'mall_order' },
  },
  // [gen:mall_order] end
  // [gen:mall_order_item] start（代码生成器维护，勿手改）
  {
    path: 'mall/order-item',
    name: 'gen-mall_order_item',
    component: () => import('@/views/gen/mall_order_item/index.vue'),
    meta: { title: '订单明细', permission: 'mall_order_item' },
  },
  // [gen:mall_order_item] end
  // [gen:category] start（代码生成器维护，勿手改）
  {
    path: 'category',
    name: 'gen-category',
    component: () => import('@/views/gen/category/index.vue'),
    meta: { title: '商品分类', permission: 'category' },
  },
  // [gen:category] end
  // [gen:product] start（代码生成器维护，勿手改）
  {
    path: 'product',
    name: 'gen-product',
    component: () => import('@/views/gen/product/index.vue'),
    meta: { title: '示例商品', permission: 'product' },
  },
  // [gen:product] end
]

export const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/login/index.vue'),
    meta: { title: '登录', public: true },
  },
  {
    path: '/',
    component: () => import('@/layout/AppLayout.vue'),
    redirect: '/dashboard',
    children: layoutRoutes,
  },
  {
    // 手机商城 demo（H5 风格，需登录后访问，接口走后台鉴权）
    path: '/shop',
    name: 'shop',
    component: () => import('@/views/shop/index.vue'),
    meta: { title: '精选商城' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'notFound',
    component: () => import('@/views/error/404.vue'),
    meta: { title: '页面不存在', public: true },
  },
]
