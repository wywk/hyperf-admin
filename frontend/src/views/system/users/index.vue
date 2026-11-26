<script setup lang="ts">
/**
 * 用户管理：账号 CRUD、启停用、重置密码。
 *
 * 后端的几条硬规则（前端也做了对应的置灰/提示，避免点了才知道不行）：
 * - 不能停用/删除自己
 * - 不能停用/删除/降级最后一个可用管理员
 * - 停用、重置密码、改角色都会立刻踢掉该用户的登录会话
 */
import { computed, onMounted, reactive, ref } from 'vue'
import type { FormInstance, FormRules } from 'element-plus'
import { Key, Plus, Refresh, Search } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import {
  createUser,
  deleteUser,
  fetchUsers,
  resetUserPassword,
  updateUser,
  updateUserStatus,
} from '@/api/users'
import { fetchRoles } from '@/api/rbac'
import type { RoleItem, UserItem, UserPayload } from '@/types'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const loading = ref(false)
const items = ref<UserItem[]>([])
const total = ref(0)
const roles = ref<RoleItem[]>([])

const query = reactive({
  page: 1,
  page_size: 10,
  keyword: '',
  role: '',
  status: '' as number | '',
})

const currentUserId = computed(() => auth.user?.id ?? 0)

async function load(): Promise<void> {
  loading.value = true
  try {
    const result = await fetchUsers({
      page: query.page,
      page_size: query.page_size,
      keyword: query.keyword.trim() || undefined,
      role: query.role || undefined,
      status: query.status === '' ? undefined : query.status,
    })
    items.value = result.items
    total.value = result.total
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    loading.value = false
  }
}

async function loadRoles(): Promise<void> {
  try {
    const result = await fetchRoles()
    roles.value = result.items
  } catch {
    // 角色列表失败不影响主体功能
  }
}

function search(): void {
  query.page = 1
  void load()
}

function reset(): void {
  query.keyword = ''
  query.role = ''
  query.status = ''
  query.page = 1
  void load()
}

function onSizeChange(): void {
  query.page = 1
  void load()
}

function roleName(code: string): string {
  return roles.value.find((role) => role.code === code)?.name ?? code
}

function roleTagType(code: string): 'danger' | 'primary' | 'info' {
  if (code === 'admin') return 'danger'
  if (code === 'user') return 'primary'
  return 'info'
}

/* ---------------- 新增 / 编辑 ---------------- */

const dialogVisible = ref(false)
const submitting = ref(false)
const editing = ref<UserItem | null>(null)
const formRef = ref<FormInstance>()
const form = reactive({
  username: '',
  password: '',
  nickname: '',
  email: '',
  role: 'user',
  status: 1,
})

const rules: FormRules = {
  username: [
    { required: true, message: '请填写用户名', trigger: 'blur' },
    { min: 3, max: 64, message: '长度需为 3-64 个字符', trigger: 'blur' },
    {
      pattern: /^[a-zA-Z][a-zA-Z0-9_.-]*$/,
      message: '以字母开头，只能包含字母、数字、下划线、点和短横线',
      trigger: 'blur',
    },
  ],
  password: [{ min: 6, max: 64, message: '密码长度需为 6-64 个字符', trigger: 'blur' }],
  email: [{ type: 'email', message: '邮箱格式不正确', trigger: 'blur' }],
  role: [{ required: true, message: '请选择角色', trigger: 'change' }],
}

const isSelf = computed(() => editing.value?.id === currentUserId.value)

function openCreate(): void {
  editing.value = null
  form.username = ''
  form.password = ''
  form.nickname = ''
  form.email = ''
  form.role = roles.value.find((role) => role.code === 'user')?.code ?? roles.value[0]?.code ?? 'user'
  form.status = 1
  dialogVisible.value = true
}

function openEdit(row: UserItem): void {
  editing.value = row
  form.username = row.username
  form.password = ''
  form.nickname = row.nickname
  form.email = row.email
  form.role = row.role
  form.status = row.status
  dialogVisible.value = true
}

async function submit(): Promise<void> {
  if (!formRef.value) return

  // 新增才校验密码必填，编辑时密码走「重置密码」入口
  if (!editing.value && form.password.trim().length < 6) {
    ElMessage.warning('请设置至少 6 位的初始密码')
    return
  }

  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) return

  submitting.value = true
  try {
    if (editing.value) {
      const payload: UserPayload = {
        username: form.username.trim(),
        nickname: form.nickname.trim(),
        email: form.email.trim(),
        role: form.role,
        status: form.status,
      }
      await updateUser(editing.value.id, payload)
      ElMessage.success('已保存')
    } else {
      await createUser({
        username: form.username.trim(),
        password: form.password,
        nickname: form.nickname.trim(),
        email: form.email.trim(),
        role: form.role,
        status: form.status,
      })
      ElMessage.success('已创建')
    }

    dialogVisible.value = false
    await load()
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    submitting.value = false
  }
}

/* ---------------- 启停用 ---------------- */

async function toggleStatus(row: UserItem): Promise<void> {
  const next = row.status === 1 ? 0 : 1

  if (next === 0) {
    try {
      await ElMessageBox.confirm(
        `停用后「${row.username}」将立即无法登录，已登录的会话也会全部失效。确定停用吗？`,
        '停用账号',
        { type: 'warning', confirmButtonText: '停用', cancelButtonText: '取消' },
      )
    } catch {
      return
    }
  }

  try {
    const updated = await updateUserStatus(row.id, next)
    row.status = updated.status
    ElMessage.success(next === 1 ? '已启用' : '已停用，该用户的会话已失效')
  } catch {
    // 最后一个管理员 / 停用自己等业务错误由请求层弹出
  }
}

/* ---------------- 重置密码 ---------------- */

const pwdVisible = ref(false)
const pwdSubmitting = ref(false)
const pwdTarget = ref<UserItem | null>(null)
const pwdFormRef = ref<FormInstance>()
const pwdForm = reactive({ password: '', confirm: '' })

const pwdRules: FormRules = {
  password: [
    { required: true, message: '请填写新密码', trigger: 'blur' },
    { min: 6, max: 64, message: '密码长度需为 6-64 个字符', trigger: 'blur' },
  ],
  confirm: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_rule: unknown, value: string, callback: (error?: Error) => void): void => {
        if (value !== pwdForm.password) {
          callback(new Error('两次输入的密码不一致'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}

function openResetPwd(row: UserItem): void {
  pwdTarget.value = row
  pwdForm.password = ''
  pwdForm.confirm = ''
  pwdVisible.value = true
}

async function submitResetPwd(): Promise<void> {
  if (!pwdFormRef.value || !pwdTarget.value) return

  const valid = await pwdFormRef.value.validate().catch(() => false)
  if (!valid) return

  pwdSubmitting.value = true
  try {
    await resetUserPassword(pwdTarget.value.id, pwdForm.password)
    ElMessage.success('密码已重置，该用户的会话已全部失效')
    pwdVisible.value = false
  } catch {
    // 错误提示由请求层统一处理
  } finally {
    pwdSubmitting.value = false
  }
}

/* ---------------- 删除 ---------------- */

async function remove(row: UserItem): Promise<void> {
  try {
    await ElMessageBox.confirm(
      `删除后账号无法恢复，该用户的登录会话会立即失效。确定删除「${row.username}」吗？`,
      '删除用户',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }

  try {
    await deleteUser(row.id)
    ElMessage.success('已删除')
    await load()
  } catch {
    // 错误提示由请求层统一处理
  }
}

onMounted(() => {
  void load()
  void loadRoles()
})
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>用户管理</h2>
        <p class="sub">
          账号、角色与状态都在这里维护。停用、重置密码、改角色会立刻让该用户已登录的会话失效。
        </p>
      </div>
      <div class="header-actions">
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon>
          <span>刷新</span>
        </el-button>
        <el-button v-permission="'user.add'" type="primary" @click="openCreate">
          <el-icon><Plus /></el-icon>
          <span>新增用户</span>
        </el-button>
      </div>
    </div>

    <el-card shadow="never" class="filter-card">
      <div class="filters">
        <el-input
          v-model="query.keyword"
          placeholder="用户名 / 昵称 / 邮箱"
          clearable
          class="w-220"
          @keyup.enter="search"
        />
        <el-select v-model="query.role" placeholder="全部角色" clearable class="w-160">
          <el-option v-for="role in roles" :key="role.code" :label="role.name" :value="role.code" />
        </el-select>
        <el-select v-model="query.status" placeholder="全部状态" clearable class="w-140">
          <el-option label="启用" :value="1" />
          <el-option label="禁用" :value="0" />
        </el-select>
        <el-button type="primary" @click="search">
          <el-icon><Search /></el-icon>
          <span>查询</span>
        </el-button>
        <el-button @click="reset">重置</el-button>
      </div>
    </el-card>

    <el-card shadow="never" class="table-card">
      <el-table v-loading="loading" :data="items" stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="用户" min-width="180">
          <template #default="{ row }">
            <div class="user-cell">
              <span class="username mono">{{ row.username }}</span>
              <span class="nickname">{{ row.nickname || '—' }}</span>
              <el-tag v-if="row.id === currentUserId" size="small" type="success" effect="plain">我</el-tag>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="角色" width="130">
          <template #default="{ row }">
            <el-tag :type="roleTagType(row.role)" effect="light" size="small">{{ roleName(row.role) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="邮箱" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <span :class="row.email ? '' : 'text-muted'">{{ row.email || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="center">
          <template #default="{ row }">
            <el-switch
              :model-value="row.status === 1"
              :disabled="row.id === currentUserId || !auth.hasPermission('user.edit')"
              @change="toggleStatus(row)"
            />
          </template>
        </el-table-column>
        <el-table-column label="最后登录" width="170">
          <template #default="{ row }">
            <span class="mono muted-sm">{{ row.last_login_at || '从未登录' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">
            <span class="mono muted-sm">{{ row.created_at || '—' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button v-permission="'user.edit'" link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button v-permission="'user.reset'" link type="warning" @click="openResetPwd(row)">
              重置密码
            </el-button>
            <el-tooltip v-if="row.id === currentUserId" content="不能删除自己" placement="top">
              <span class="disabled-action">删除</span>
            </el-tooltip>
            <el-button
              v-else-if="auth.hasPermission('user.delete')"
              link
              type="danger"
              @click="remove(row)"
            >
              删除
            </el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="没有符合条件的用户" :image-size="80" />
        </template>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.page_size"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @current-change="load"
          @size-change="onSizeChange"
        />
      </div>
    </el-card>

    <!-- 新增 / 编辑 -->
    <el-dialog
      v-model="dialogVisible"
      :title="editing ? '编辑用户' : '新增用户'"
      width="520px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form ref="formRef" :model="form" :rules="rules" label-width="90px">
        <el-form-item label="用户名" prop="username">
          <el-input v-model="form.username" placeholder="登录名，字母开头" class="mono-input" />
        </el-form-item>
        <el-form-item v-if="!editing" label="初始密码" prop="password">
          <el-input v-model="form.password" type="password" show-password placeholder="至少 6 位" />
        </el-form-item>
        <el-form-item label="昵称" prop="nickname">
          <el-input v-model="form.nickname" maxlength="64" placeholder="用于页面展示" />
        </el-form-item>
        <el-form-item label="邮箱" prop="email">
          <el-input v-model="form.email" maxlength="128" placeholder="可选" />
        </el-form-item>
        <el-form-item label="角色" prop="role">
          <el-select v-model="form.role" :disabled="isSelf" class="w-full">
            <el-option v-for="role in roles" :key="role.code" :label="`${role.name}（${role.code}）`" :value="role.code" />
          </el-select>
          <p v-if="isSelf" class="field-tip">不能修改自己的角色，避免把自己锁在后台外面。</p>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" :disabled="isSelf" />
          <span class="field-tip inline">停用后该用户无法登录，已有会话立即失效</span>
        </el-form-item>
        <el-alert
          v-if="editing"
          type="info"
          :closable="false"
          show-icon
          title="密码不在这里修改，请使用列表里的「重置密码」。"
        />
      </el-form>

      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submit">
          {{ editing ? '保存' : '创建' }}
        </el-button>
      </template>
    </el-dialog>

    <!-- 重置密码 -->
    <el-dialog
      v-model="pwdVisible"
      :title="pwdTarget ? `重置密码 · ${pwdTarget.username}` : '重置密码'"
      width="440px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-alert
        type="warning"
        :closable="false"
        show-icon
        title="重置后该用户的全部登录会话立即失效，需要用新密码重新登录。"
        class="pwd-alert"
      />
      <el-form ref="pwdFormRef" :model="pwdForm" :rules="pwdRules" label-width="90px">
        <el-form-item label="新密码" prop="password">
          <el-input v-model="pwdForm.password" type="password" show-password placeholder="至少 6 位" />
        </el-form-item>
        <el-form-item label="确认密码" prop="confirm">
          <el-input v-model="pwdForm.confirm" type="password" show-password placeholder="再输一次" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="pwdVisible = false">取消</el-button>
        <el-button type="primary" :loading="pwdSubmitting" @click="submitResetPwd">
          <el-icon><Key /></el-icon>
          <span>确认重置</span>
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.header-actions {
  display: flex;
  gap: 10px;
}

.filter-card,
.table-card {
  border-radius: var(--app-radius);
}

.filter-card {
  margin-bottom: 14px;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.w-220 {
  width: 220px;
}

.w-160 {
  width: 160px;
}

.w-140 {
  width: 140px;
}

.w-full {
  width: 100%;
}

.user-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.username {
  font-size: 13px;
  font-weight: 600;
}

.nickname {
  color: var(--app-muted);
  font-size: 12.5px;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.mono-input :deep(input) {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

.muted-sm {
  font-size: 12px;
  color: var(--app-muted);
}

.disabled-action {
  color: #c0c4cc;
  font-size: 13px;
  cursor: not-allowed;
  padding: 0 4px;
}

.field-tip {
  margin: 4px 0 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--app-muted);
}

.field-tip.inline {
  margin: 0 0 0 10px;
}

.pwd-alert {
  margin-bottom: 14px;
}

.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 14px;
}
</style>
