<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import type { FormInstance, FormRules } from 'element-plus'
import { Lock, Iphone } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { useAuthStore } from '@/stores/auth'
import { changeOwnPassword } from '@/api/users'
import { disableTotpApi, enableTotpApi, setupTotpApi } from '@/api/auth'
import { getExpiresAt, getRefreshToken } from '@/utils/token'

const auth = useAuthStore()
const router = useRouter()

const accessExpiresAt = computed(() => {
  const timestamp = getExpiresAt()
  return timestamp ? new Date(timestamp).toLocaleString('zh-CN') : '—'
})

const hasRefreshToken = computed(() => Boolean(getRefreshToken()))

const roleLabel = computed(() => (auth.user?.role === 'admin' ? '管理员' : '普通用户'))

async function logout(): Promise<void> {
  try {
    await ElMessageBox.confirm('确定要退出当前账号吗？', '退出登录', {
      type: 'warning',
      confirmButtonText: '退出',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  await auth.logout()
  ElMessage.success('已退出登录')
  await router.replace({ name: 'login' })
}

/* ---------------- 修改密码 ---------------- */

const pwdVisible = ref(false)
const pwdSubmitting = ref(false)
const pwdFormRef = ref<FormInstance>()
const pwdForm = reactive({ old_password: '', new_password: '', confirm: '' })

const pwdRules: FormRules = {
  old_password: [
    { required: true, message: '请输入当前密码', trigger: 'blur' },
    { min: 6, max: 64, message: '密码长度需为 6-64 个字符', trigger: 'blur' },
  ],
  new_password: [
    { required: true, message: '请输入新密码', trigger: 'blur' },
    { min: 8, max: 64, message: '新密码至少 8 位，且需同时包含字母和数字', trigger: 'blur' },
    {
      validator: (_rule: unknown, value: string, callback: (error?: Error) => void): void => {
        if (value && value === pwdForm.old_password) {
          callback(new Error('新密码不能与当前密码相同'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
  confirm: [
    { required: true, message: '请再次输入新密码', trigger: 'blur' },
    {
      validator: (_rule: unknown, value: string, callback: (error?: Error) => void): void => {
        if (value !== pwdForm.new_password) {
          callback(new Error('两次输入的新密码不一致'))
          return
        }
        callback()
      },
      trigger: 'blur',
    },
  ],
}

function openChangePassword(): void {
  pwdForm.old_password = ''
  pwdForm.new_password = ''
  pwdForm.confirm = ''
  pwdVisible.value = true
}

async function submitChangePassword(): Promise<void> {
  if (!pwdFormRef.value) return

  const valid = await pwdFormRef.value.validate().catch(() => false)
  if (!valid) return

  pwdSubmitting.value = true
  try {
    await changeOwnPassword(pwdForm.old_password, pwdForm.new_password)
    pwdVisible.value = false
    ElMessage.success('密码已更新，其他设备的登录已失效')
    await auth.fetchProfile(true)
  } catch {
    // 旧密码错误等提示由请求层统一处理
  } finally {
    pwdSubmitting.value = false
  }
}

/* ---------------- 两步验证 ---------------- */

const totpEnabled = computed(() => Boolean(auth.user?.totp_enabled))
const totpVisible = ref(false)
const totpMode = ref<'setup' | 'disable'>('setup')
const totpSecret = ref('')
const totpUri = ref('')
const totpCode = ref('')
const totpBusy = ref(false)

async function openTotp(): Promise<void> {
  totpCode.value = ''
  totpSecret.value = ''
  totpUri.value = ''

  if (totpEnabled.value) {
    totpMode.value = 'disable'
    totpVisible.value = true
    return
  }

  totpMode.value = 'setup'
  totpBusy.value = true
  try {
    const result = await setupTotpApi()
    totpSecret.value = result.secret
    totpUri.value = result.otpauth_url
    totpVisible.value = true
  } catch {
    // 请求层已提示
  } finally {
    totpBusy.value = false
  }
}

async function copySecret(): Promise<void> {
  try {
    await navigator.clipboard.writeText(totpSecret.value)
    ElMessage.success('密钥已复制')
  } catch {
    ElMessage.warning('复制失败，请手动选择密钥文本')
  }
}

async function submitTotp(): Promise<void> {
  if (!/^\d{6}$/.test(totpCode.value.trim())) {
    ElMessage.warning('请输入 6 位动态验证码')
    return
  }

  totpBusy.value = true
  try {
    if (totpMode.value === 'setup') {
      await enableTotpApi(totpCode.value.trim())
      ElMessage.success('两步验证已开启，下次登录需要输入动态码')
    } else {
      await disableTotpApi(totpCode.value.trim())
      ElMessage.success('两步验证已关闭')
    }
    totpVisible.value = false
    await auth.fetchProfile(true)
  } catch {
    // 错误提示由请求层处理，动态码错误时保留弹窗让用户重试
    totpCode.value = ''
  } finally {
    totpBusy.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>个人中心</h2>
        <p class="sub">展示当前登录用户信息与会话状态，数据来自 <code class="mono">GET /api/auth/me</code>。</p>
      </div>
    </div>

    <el-row :gutter="16">
      <el-col :xs="24" :md="8">
        <el-card shadow="never" class="card profile">
          <el-avatar :size="64" class="avatar">{{ auth.avatarText }}</el-avatar>
          <h3>{{ auth.displayName }}</h3>
          <p class="text-muted mono">@{{ auth.user?.username }}</p>
          <el-tag :type="auth.user?.role === 'admin' ? 'danger' : 'info'" effect="light">
            {{ roleLabel }}
          </el-tag>

          <el-divider />

          <div class="meta">
            <div><span>用户 ID</span><strong>{{ auth.user?.id }}</strong></div>
            <div><span>状态</span><strong>{{ auth.user?.status === 1 ? '启用' : '禁用' }}</strong></div>
            <div><span>上次登录</span><strong>{{ auth.user?.last_login_at || '—' }}</strong></div>
          </div>

          <el-button type="danger" plain class="logout" @click="logout">退出登录</el-button>
        </el-card>
      </el-col>

      <el-col :xs="24" :md="16">
        <el-card shadow="never" class="card">
          <template #header>
            <div class="card-head">资料</div>
          </template>
          <el-descriptions :column="1" border size="default">
            <el-descriptions-item label="用户名">{{ auth.user?.username }}</el-descriptions-item>
            <el-descriptions-item label="昵称">{{ auth.user?.nickname || '—' }}</el-descriptions-item>
            <el-descriptions-item label="邮箱">{{ auth.user?.email || '—' }}</el-descriptions-item>
            <el-descriptions-item label="角色">
              <el-space>
                <el-tag v-for="role in auth.roles" :key="role" size="small" class="mono">{{ role }}</el-tag>
              </el-space>
            </el-descriptions-item>
            <el-descriptions-item label="所属部门">{{ auth.user?.dept_name || '未分配' }}</el-descriptions-item>
            <el-descriptions-item label="数据范围">{{ auth.user?.data_scope_text || '全部数据' }}</el-descriptions-item>
            <el-descriptions-item label="创建时间">{{ auth.user?.created_at || '—' }}</el-descriptions-item>
          </el-descriptions>
        </el-card>

        <el-card shadow="never" class="card">
          <template #header>
            <div class="card-head">权限与会话</div>
          </template>

          <div class="tags">
            <el-tag v-for="code in auth.permissions" :key="code" class="mono" type="success" effect="plain">
              {{ code }}
            </el-tag>
            <span v-if="!auth.permissions.length" class="text-muted">无权限码</span>
          </div>

          <el-descriptions :column="1" border size="small" class="session">
            <el-descriptions-item label="Access Token 过期时间">{{ accessExpiresAt }}</el-descriptions-item>
            <el-descriptions-item label="Refresh Token">
              {{ hasRefreshToken ? '已持有，过期自动续期' : '未持有' }}
            </el-descriptions-item>
          </el-descriptions>

          <div class="pwd-row">
            <el-button type="primary" plain @click="openChangePassword">
              <el-icon><Lock /></el-icon>
              <span>修改密码</span>
            </el-button>
            <span class="text-muted pwd-tip">修改后其他设备上的登录会立即失效，当前设备保持登录。</span>
          </div>
        </el-card>

        <el-card shadow="never" class="card">
          <template #header>
            <div class="card-head">安全设置</div>
          </template>

          <el-alert
            v-if="auth.user?.password_expired"
            title="登录密码已超过有效期，建议立即修改"
            type="warning"
            show-icon
            :closable="false"
            class="pwd-alert"
          />

          <el-descriptions :column="1" border size="small">
            <el-descriptions-item label="密码最近修改">
              {{ auth.user?.password_changed_at || '—' }}
            </el-descriptions-item>
            <el-descriptions-item label="密码有效期">
              <template v-if="auth.user?.password_expires_in === null || auth.user?.password_expires_in === undefined">
                未开启有效期限制
              </template>
              <template v-else>
                还剩 {{ auth.user?.password_expires_in }} 天
              </template>
            </el-descriptions-item>
            <el-descriptions-item label="两步验证">
              <el-space>
                <el-tag :type="totpEnabled ? 'success' : 'info'" effect="plain" size="small">
                  {{ totpEnabled ? '已开启' : '未开启' }}
                </el-tag>
                <span class="text-muted pwd-tip">
                  {{ totpEnabled ? '登录时需要输入认证器 App 的动态码' : '建议管理员账号开启，防止密码泄露后被直接登录' }}
                </span>
              </el-space>
            </el-descriptions-item>
          </el-descriptions>

          <div class="pwd-row">
            <el-button :type="totpEnabled ? 'danger' : 'primary'" plain :loading="totpBusy" @click="openTotp">
              <el-icon><Iphone /></el-icon>
              <span>{{ totpEnabled ? '关闭两步验证' : '开启两步验证' }}</span>
            </el-button>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 修改密码 -->
    <el-dialog
      v-model="pwdVisible"
      title="修改密码"
      width="440px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <el-form ref="pwdFormRef" :model="pwdForm" :rules="pwdRules" label-width="90px">
        <el-form-item label="当前密码" prop="old_password">
          <el-input v-model="pwdForm.old_password" type="password" show-password />
        </el-form-item>
        <el-form-item label="新密码" prop="new_password">
          <el-input v-model="pwdForm.new_password" type="password" show-password placeholder="至少 8 位，含字母和数字" />
        </el-form-item>
        <el-form-item label="确认密码" prop="confirm">
          <el-input v-model="pwdForm.confirm" type="password" show-password placeholder="再输一次" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="pwdVisible = false">取消</el-button>
        <el-button type="primary" :loading="pwdSubmitting" @click="submitChangePassword">确认修改</el-button>
      </template>
    </el-dialog>

    <!-- 两步验证 -->
    <el-dialog
      v-model="totpVisible"
      :title="totpMode === 'setup' ? '开启两步验证' : '关闭两步验证'"
      width="460px"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <template v-if="totpMode === 'setup'">
        <el-alert
          title="在认证器 App（如 Google Authenticator / 1Password）中手动添加以下密钥"
          type="info"
          show-icon
          :closable="false"
        />

        <div class="secret-box">
          <span class="mono secret">{{ totpSecret }}</span>
          <el-button link type="primary" size="small" @click="copySecret">复制</el-button>
        </div>

        <p class="text-muted hint">也可以把下面的链接粘贴到支持 otpauth 的工具里：</p>
        <p class="mono uri">{{ totpUri }}</p>
      </template>

      <el-alert
        v-else
        title="关闭后登录将不再需要动态验证码，账号安全性会下降"
        type="warning"
        show-icon
        :closable="false"
      />

      <el-form label-position="top" class="totp-form">
        <el-form-item :label="totpMode === 'setup' ? '输入 App 中显示的动态码完成绑定' : '输入当前动态码以确认'">
          <el-input v-model="totpCode" maxlength="6" placeholder="6 位数字" class="mono-input" />
        </el-form-item>
      </el-form>

      <template #footer>
        <el-button @click="totpVisible = false">取消</el-button>
        <el-button :type="totpMode === 'setup' ? 'primary' : 'danger'" :loading="totpBusy" @click="submitTotp">
          {{ totpMode === 'setup' ? '确认开启' : '确认关闭' }}
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.card {
  border-radius: var(--app-radius);
  margin-bottom: 16px;
}

.card-head {
  font-size: 14px;
  font-weight: 600;
}

.profile {
  text-align: center;
}

.avatar {
  background: var(--app-brand);
  color: #fff;
  font-size: 24px;
}

.profile h3 {
  margin: 12px 0 2px;
  font-size: 17px;
}

.profile p {
  margin: 0 0 10px;
  font-size: 12.5px;
}

.meta {
  display: flex;
  flex-direction: column;
  gap: 10px;
  text-align: left;
  font-size: 13px;
}

.meta div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.meta span {
  color: var(--app-muted);
}

.meta strong {
  font-weight: 500;
}

.logout {
  width: 100%;
  margin-top: 20px;
}

.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.session {
  margin-top: 16px;
}

.pwd-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 16px;
  flex-wrap: wrap;
}

.pwd-tip {
  font-size: 12px;
}

.pwd-alert {
  margin-bottom: 14px;
}

.secret-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin: 12px 0;
  padding: 10px 12px;
  border: 1px dashed var(--app-border);
  border-radius: 8px;
  background: var(--el-fill-color-lighter);
}

.secret {
  font-size: 13px;
  letter-spacing: 1px;
  word-break: break-all;
}

.hint {
  margin: 0 0 6px;
  font-size: 12px;
}

.uri {
  margin: 0;
  font-size: 11px;
  color: var(--app-muted);
  word-break: break-all;
}

.totp-form {
  margin-top: 14px;
}

.mono-input :deep(input) {
  letter-spacing: 6px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

code.mono {
  background: #f3f4f6;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 12px;
}
</style>
