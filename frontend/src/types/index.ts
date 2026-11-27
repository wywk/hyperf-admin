/** 后端统一响应结构 */
export interface ApiEnvelope<T> {
  code: number
  message: string
  data: T
}

export interface TokenPair {
  access_token: string
  token_type: string
  expires_in: number
  expires_at: string
  refresh_token: string
  refresh_expires_in: number
  refresh_expires_at: string
}

export interface UserProfile {
  id: number
  username: string
  nickname: string
  email: string
  status: number
  role: string
  roles: string[]
  /** 按钮码（含菜单码），v-permission 与路由守卫用它判断 */
  permissions: string[]
  /** 菜单树，侧边栏直接渲染它 */
  menus: MenuNode[]
  last_login_at: string | null
  created_at: string | null
  /** 是否开启两步验证 */
  totp_enabled?: boolean
  /** 密码是否已过期（过期不阻断登录，仅提示改密） */
  password_expired?: boolean
  /** 距离密码过期还剩几天；未开启有效期策略为 null */
  password_expires_in?: number | null
  password_changed_at?: string | null
  /** 数据权限范围与所属部门（后端 /auth/me 返回） */
  data_scope?: DataScope
  data_scope_text?: string
  dept_id?: number | null
  dept_name?: string
}

export interface LoginResult extends TokenPair {
  user: UserProfile
}

/** 开启两步验证的账号：密码通过后先拿一次性凭证，再换正式 token */
export interface TotpChallenge {
  requires_totp: true
  totp_token: string
  expires_in: number
}

export type LoginResponse = LoginResult | TotpChallenge

export interface LoginPayload {
  username: string
  password: string
  captcha_id?: string
  captcha_code?: string
}

export interface CaptchaChallenge {
  required: boolean
  captcha_id?: string
  image?: string
  expires_in?: number
}

export interface LoginLogItem {
  id: number
  user_id: number | null
  username: string
  event: string
  event_text: string
  ip: string
  user_agent: string
  message: string
  risk_level: number
  risk_text: string
  risk_reasons: string
  created_at: string | null
}

export interface PageResult<T> {
  total: number
  page: number
  page_size: number
  items: T[]
}

export interface LoginLogQuery {
  page?: number
  page_size?: number
  event?: string
  username?: string
  ip?: string
  risk_level?: number
}

export interface OperationLogItem {
  id: number
  user_id: number
  username: string
  method: string
  path: string
  action: string
  params: Record<string, unknown> | null
  has_diff: boolean
  status_code: number
  cost_ms: number
  ip: string
  user_agent: string
  created_at: string | null
}

/** 字段级变更快照（操作日志详情） */
export interface OpLogDiffEntry {
  table: string
  id: number | null
  field: string
  before: string | null
  after: string | null
}

export interface OperationLogDetail extends OperationLogItem {
  diff: OpLogDiffEntry[] | null
}

export interface OperationLogQuery {
  page?: number
  page_size?: number
  user_id?: number
  username?: string
  method?: string
  path?: string
  status_code?: number
}

export interface CrontabTaskItem {
  id: number
  name: string
  handler: string
  rule: string
  params: Record<string, unknown> | null
  status: number
  consecutive_failures: number
  last_run_at: string | null
  next_run_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface CrontabLogItem {
  id: number
  task_id: number
  status: number
  status_text: string
  output: string
  cost_ms: number
  created_at: string | null
}

export interface CrontabPayload {
  name: string
  handler: string
  rule: string
  params?: Record<string, unknown> | null
  status?: number
}

/** 后端返回的菜单树节点（type=menu 的权限节点，已按角色过滤） */
export interface MenuNode {
  id: number
  code: string
  name: string
  /** 路由路径（相对布局，如 system/permissions） */
  path: string
  /** Element Plus 图标组件名 */
  icon: string
  sort: number
  children: MenuNode[]
}

/** 权限树节点（后端 permissions 表，type: menu=菜单/目录，button=按钮） */
export interface PermissionNode {
  id: number
  parent_id: number
  type: 'menu' | 'button'
  code: string
  name: string
  /** 路由路径（仅菜单） */
  path: string
  /** 图标名（仅菜单） */
  icon: string
  /** 1=侧边栏显示（仅菜单） */
  visible: number
  /** 1=内置：不可删除、不可改权限码 */
  is_system: number
  sort: number
  description: string
  role_count?: number
  created_at: string | null
  updated_at: string | null
  children: PermissionNode[]
}

export interface PermissionTreeResult {
  /** keyword 为空时是整棵树；有关键字时是平铺的过滤结果 */
  items: PermissionNode[]
  flat: boolean
}

export interface PermissionPayload {
  parent_id?: number
  /** 创建后不可改 */
  type?: 'menu' | 'button'
  code?: string
  name?: string
  path?: string
  icon?: string
  visible?: number
  sort?: number
  description?: string
}

/** 角色（后端 roles 表） */
export interface RoleItem {
  id: number
  code: string
  name: string
  description: string
  /** 数据范围：all/dept/dept_and_children/self */
  data_scope: DataScope
  /** 1=内置：不可删除、不可改标识 */
  is_system: number
  sort: number
  user_count?: number
  permission_count?: number
  created_at: string | null
}

/** 数据权限范围 */
export type DataScope = 'all' | 'dept' | 'dept_and_children' | 'self'

export interface RolePayload {
  code?: string
  name?: string
  description?: string
  sort?: number
  data_scope?: DataScope
}

/* ===================== 用户管理 ===================== */

/** 用户列表项（后端 users 表，不含密码） */
export interface UserItem {
  id: number
  username: string
  nickname: string
  email: string
  role: string
  role_name?: string
  dept_id: number | null
  dept_name?: string
  status: number
  last_login_at: string | null
  created_at: string | null
}

export interface UserQuery {
  page?: number
  page_size?: number
  keyword?: string
  role?: string
  status?: number | ''
  dept_id?: number | ''
}

export interface UserPayload {
  username?: string
  password?: string
  nickname?: string
  email?: string
  role?: string
  dept_id?: number | null
  status?: number
}

/* ===================== 部门 ===================== */

export interface DepartmentNode {
  id: number
  parent_id: number
  name: string
  code: string
  leader: string
  phone: string
  sort: number
  status: number
  path: string
  user_count?: number
  created_at?: string | null
  children: DepartmentNode[]
}

export interface DepartmentPayload {
  parent_id?: number
  name?: string
  code?: string
  leader?: string
  phone?: string
  sort?: number
  status?: number
}

/* ===================== 在线会话 ===================== */

export interface SessionItem {
  user_id: number
  username: string
  nickname: string
  role: string
  user_status: number
  family_id: string
  ip: string
  user_agent: string
  browser: string
  os: string
  login_at: string
  last_seen: string
  online: boolean
  is_current: boolean
}

export interface SessionResult {
  total: number
  online: number
  items: SessionItem[]
}

/* ===================== 系统配置 ===================== */

export type ConfigValueType = 'string' | 'text' | 'number' | 'boolean' | 'json'

export interface ConfigItem {
  key: string
  value: string | number | boolean | unknown
  type: ConfigValueType
  name: string
  description: string
  sort: number
  is_system: number
}

export interface ConfigGroup {
  group: string
  name: string
  items: ConfigItem[]
}

/* ===================== 表单设计器 ===================== */

/** 表单字段控件类型 */
export type FormFieldType =
  | 'input'
  | 'textarea'
  | 'number'
  | 'select'
  | 'radio'
  | 'checkbox'
  | 'date'
  | 'switch'

/** JSON Schema 里的单个字段定义（设计器导出格式） */
export interface FormFieldSchema {
  /** 字段名：小写字母开头，字母/数字/下划线 */
  field: string
  label: string
  type: FormFieldType
  required?: boolean
  placeholder?: string
  /** 默认值 */
  default?: unknown
  /** select/radio/checkbox 的选项（字符串数组） */
  options?: string[]
  min?: number
  max?: number
}

/** 表单 Schema */
export interface FormSchema {
  fields: FormFieldSchema[]
}

/** 表单定义（后端 forms 表） */
export interface FormDefinition {
  id: number
  name: string
  code: string
  description: string
  status: number
  schema: FormSchema | null
  created_at: string | null
  updated_at: string | null
}

export interface FormPayload {
  name: string
  code: string
  description?: string
  schema: FormSchema
  status?: number
}

/** 表单提交记录 */
export interface FormSubmissionItem {
  id: number
  form_id: number
  data: Record<string, unknown>
  client_ip: string
  created_at: string | null
}

/** 站内消息 */
export type NotificationType = 'system' | 'crontab' | 'account' | 'security'
export type NotificationLevel = 'info' | 'success' | 'warning' | 'error'

export interface NotificationItem {
  id: number
  type: NotificationType
  type_text: string
  level: NotificationLevel
  title: string
  content: string
  link: string
  is_read: boolean
  read_at: string | null
  created_at: string | null
}

export interface NotificationResult {
  total: number
  page: number
  page_size: number
  unread: number
  items: NotificationItem[]
}

export interface NotificationSummary {
  unread: number
  total: number
  by_type: Partial<Record<NotificationType, number>>
}

/** 异步任务（任务中心） */
export type JobType = 'export.login_logs' | 'export.operation_logs' | 'export.products'
export type JobStatus = 0 | 1 | 2 | 3

export interface JobItem {
  id: number
  type: JobType | string
  type_text: string
  status: JobStatus
  attempts: number
  error: string
  result_file_id: number | null
  user_id: number | null
  duration_ms: number | null
  created_at: string | null
  finished_at: string | null
}

export interface JobResult {
  total: number
  page: number
  page_size: number
  items: JobItem[]
}
