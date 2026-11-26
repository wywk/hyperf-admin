<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { FormInstance, FormRules } from 'element-plus'
import { Document, Key, Lock, User } from '@element-plus/icons-vue'
import { ElMessage } from '@/plugins/element'
import { useAuthStore } from '@/stores/auth'
import { RequestError } from '@/api/http'
import { fetchCaptchaApi, verifyTotpApi } from '@/api/auth'
import type { LoginResult } from '@/types'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const formRef = ref<FormInstance>()
const form = reactive({ username: '', password: '' })
const errorMessage = ref('')

const rules: FormRules = {
  username: [
    { required: true, message: '请输入用户名', trigger: 'blur' },
    { min: 3, max: 64, message: '用户名长度需为 3-64 个字符', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 6, max: 64, message: '密码长度需为 6-64 个字符', trigger: 'blur' },
  ],
}

const accounts = [
  { label: '管理员', username: 'admin', password: 'admin123456', desc: '全部权限' },
  { label: '普通用户', username: 'demo', password: 'demo123456', desc: '仅仪表盘/个人中心' },
]

function fill(account: (typeof accounts)[number]): void {
  form.username = account.username
  form.password = account.password
  errorMessage.value = ''
  void refreshCaptcha()
}

/* ---------------- 图形验证码 ---------------- */

const captcha = reactive({ required: false, id: '', image: '' })
const captchaCode = ref('')
const captchaLoading = ref(false)

/**
 * 每次失败后都重新问一次：失败次数到阈值时后端会开始要求验证码，
 * 前端只要跟着这个开关走就行，不用自己维护计数。
 */
async function refreshCaptcha(): Promise<void> {
  captchaLoading.value = true
  try {
    const result = await fetchCaptchaApi(form.username.trim())
    captcha.required = result.required
    captcha.id = result.captcha_id ?? ''
    captcha.image = result.image ?? ''
    captchaCode.value = ''
  } catch {
    // 拿不到验证码状态时不阻塞登录，失败后自然会重试
  } finally {
    captchaLoading.value = false
  }
}

/* ---------------- 登录（含两步验证） ---------------- */

const totpToken = ref('')
const totpCode = ref('')
const totpSubmitting = ref(false)

function finishLogin(result: LoginResult): void {
  auth.completeLogin(result)
  ElMessage.success('登录成功')
}

async function goAfterLogin(): Promise<void> {
  // 只接受站内相对路径，避免开放重定向
  const redirect = route.query.redirect
  const target = typeof redirect === 'string' && redirect.startsWith('/') ? redirect : '/'

  // 跳转失败不能当成登录失败：此时 token 已经拿到，提示「登录失败」会误导用户
  try {
    await router.replace(target)
  } catch {
    await router.replace('/')
  }
}

async function submit(): Promise<void> {
  if (!formRef.value) {
    return
  }

  const valid = await formRef.value.validate().catch(() => false)
  if (!valid) {
    return
  }

  errorMessage.value = ''

  try {
    const result = await auth.login({
      username: form.username.trim(),
      password: form.password,
      ...(captcha.required ? { captcha_id: captcha.id, captcha_code: captchaCode.value.trim() } : {}),
    })

    if ('requires_totp' in result) {
      totpToken.value = result.totp_token
      totpCode.value = ''
      return
    }

    finishLogin(result)
    await goAfterLogin()
  } catch (error) {
    errorMessage.value = error instanceof RequestError ? error.message : '登录失败，请稍后重试'
    // 失败次数可能刚好越过阈值，下一次就要验证码了
    await refreshCaptcha()
  }
}

async function submitTotp(): Promise<void> {
  if (!/^\d{6}$/.test(totpCode.value.trim())) {
    errorMessage.value = '请输入 6 位动态验证码'
    return
  }

  errorMessage.value = ''
  totpSubmitting.value = true
  try {
    const result = await verifyTotpApi(totpToken.value, totpCode.value.trim())
    finishLogin(result)
    await goAfterLogin()
  } catch (error) {
    errorMessage.value = error instanceof RequestError ? error.message : '验证失败，请重试'
    totpCode.value = ''
  } finally {
    totpSubmitting.value = false
  }
}

function backToPassword(): void {
  totpToken.value = ''
  totpCode.value = ''
  errorMessage.value = ''
  void refreshCaptcha()
}

onMounted(() => {
  void refreshCaptcha()
})
</script>

<template>
  <div class="login-page">
    <section class="intro">
      <div class="intro-inner">
        <div class="logo">H</div>
        <h1>Hyperf Admin</h1>
        <p class="slogan">Vue 3 + Element Plus 管理后台脚手架<br />对接 Hyperf 3.1 认证接口</p>

        <ul class="features">
          <li>
            <el-icon><Key /></el-icon>
            <span>JWT 双 token，过期自动静默刷新</span>
          </li>
          <li>
            <el-icon><Lock /></el-icon>
            <span>路由守卫 + 菜单过滤 + 按钮级权限</span>
          </li>
          <li>
            <el-icon><Document /></el-icon>
            <span>内置接口文档入口与统一响应处理</span>
          </li>
        </ul>

        <div class="tech">
          <span>Vue 3.5</span>
          <span>Element Plus</span>
          <span>Pinia</span>
          <span>Vite</span>
          <span>TypeScript</span>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="card">
        <h2>账号登录</h2>
        <p class="hint">请使用后台分配的账号登录</p>

        <el-alert
          v-if="errorMessage"
          :title="errorMessage"
          type="error"
          show-icon
          :closable="false"
          class="alert"
        />

        <el-form
          v-if="!totpToken"
          ref="formRef"
          :model="form"
          :rules="rules"
          label-position="top"
          size="large"
          @submit.prevent="submit"
        >
          <el-form-item label="用户名" prop="username">
            <el-input v-model="form.username" placeholder="请输入用户名" clearable autocomplete="username">
              <template #prefix>
                <el-icon><User /></el-icon>
              </template>
            </el-input>
          </el-form-item>

          <el-form-item label="密码" prop="password">
            <el-input
              v-model="form.password"
              type="password"
              placeholder="请输入密码"
              show-password
              autocomplete="current-password"
              @keyup.enter="submit"
            >
              <template #prefix>
                <el-icon><Lock /></el-icon>
              </template>
            </el-input>
          </el-form-item>

          <el-form-item v-if="captcha.required" label="图形验证码">
            <div class="captcha-row">
              <el-input
                v-model="captchaCode"
                placeholder="输入右侧 4 位字符"
                maxlength="4"
                autocomplete="off"
                @keyup.enter="submit"
              />
              <img
                v-if="captcha.image"
                :src="captcha.image"
                class="captcha-img"
                alt="验证码"
                title="看不清？点击换一张"
                @click="refreshCaptcha"
              />
              <el-button v-else text :loading="captchaLoading" @click="refreshCaptcha">获取</el-button>
            </div>
          </el-form-item>

          <el-button type="primary" class="submit" :loading="auth.submitting" @click="submit">
            {{ auth.submitting ? '登录中…' : '登 录' }}
          </el-button>
        </el-form>

        <!-- 两步验证第二步：密码已经过了，这里只要 6 位动态码 -->
        <div v-else class="totp-step">
          <el-alert
            title="该账号已开启两步验证"
            description="请打开认证器 App，输入当前 6 位动态验证码。"
            type="info"
            show-icon
            :closable="false"
            class="alert"
          />

          <el-form label-position="top" size="large" @submit.prevent="submitTotp">
            <el-form-item label="动态验证码">
              <el-input
                v-model="totpCode"
                placeholder="6 位数字"
                maxlength="6"
                class="totp-input"
                autofocus
                @keyup.enter="submitTotp"
              >
                <template #prefix>
                  <el-icon><Key /></el-icon>
                </template>
              </el-input>
            </el-form-item>

            <el-button type="primary" class="submit" :loading="totpSubmitting" @click="submitTotp">
              {{ totpSubmitting ? '验证中…' : '验 证' }}
            </el-button>
          </el-form>

          <el-button link class="back" @click="backToPassword">返回重新登录</el-button>
        </div>

        <div v-if="!totpToken" class="accounts">
          <span class="accounts-title">测试账号（点击填充）</span>
          <div class="account-list">
            <button
              v-for="account in accounts"
              :key="account.username"
              type="button"
              class="account"
              @click="fill(account)"
            >
              <strong>{{ account.label }}</strong>
              <span class="mono">{{ account.username }} / {{ account.password }}</span>
              <em>{{ account.desc }}</em>
            </button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.login-page {
  display: grid;
  grid-template-columns: 1.1fr 1fr;
  min-height: 100vh;
  background: #f5f7fa;
}

.intro {
  position: relative;
  display: flex;
  align-items: center;
  padding: 64px;
  color: #fff;
  background: radial-gradient(circle at 20% 20%, #3b82f6 0%, #1e3a8a 55%, #0f1f4d 100%);
  overflow: hidden;
}

.intro::after {
  content: '';
  position: absolute;
  inset: auto -120px -160px auto;
  width: 420px;
  height: 420px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.08);
}

.intro-inner {
  position: relative;
  z-index: 1;
  max-width: 460px;
}

.logo {
  width: 46px;
  height: 46px;
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.16);
  border: 1px solid rgba(255, 255, 255, 0.28);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  font-weight: 700;
}

.intro h1 {
  margin: 22px 0 10px;
  font-size: 30px;
  letter-spacing: 0.5px;
}

.slogan {
  margin: 0;
  color: rgba(255, 255, 255, 0.78);
  line-height: 1.75;
  font-size: 14px;
}

.features {
  list-style: none;
  margin: 34px 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.features li {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13.5px;
  color: rgba(255, 255, 255, 0.9);
}

.tech {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 36px;
}

.tech span {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 11.5px;
  background: rgba(255, 255, 255, 0.12);
  border: 1px solid rgba(255, 255, 255, 0.16);
  color: rgba(255, 255, 255, 0.86);
}

.panel {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 28px;
}

.card {
  width: 100%;
  max-width: 380px;
  background: #fff;
  border-radius: 14px;
  padding: 32px 30px 26px;
  box-shadow: 0 12px 40px rgba(15, 23, 42, 0.08);
  border: 1px solid #eef0f3;
}

.card h2 {
  margin: 0;
  font-size: 20px;
}

.hint {
  margin: 6px 0 20px;
  font-size: 13px;
  color: var(--app-muted);
}

.alert {
  margin-bottom: 14px;
}

.submit {
  width: 100%;
  margin-top: 4px;
  letter-spacing: 2px;
}

.captcha-row {
  display: flex;
  gap: 10px;
  align-items: center;
  width: 100%;
}

.captcha-img {
  height: 40px;
  width: 120px;
  border-radius: 6px;
  cursor: pointer;
  border: 1px solid var(--app-border);
  flex-shrink: 0;
}

.totp-step {
  margin-top: 4px;
}

.totp-input :deep(input) {
  letter-spacing: 8px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 18px;
}

.back {
  width: 100%;
  margin-top: 10px;
}

.accounts {
  margin-top: 24px;
  padding-top: 18px;
  border-top: 1px dashed var(--app-border);
}

.accounts-title {
  font-size: 12px;
  color: var(--app-muted);
}

.account-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 10px;
}

.account {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 9px 12px;
  border-radius: 8px;
  border: 1px solid var(--app-border);
  background: #fafbfc;
  cursor: pointer;
  text-align: left;
  transition: border-color 0.15s, background 0.15s;
  font: inherit;
}

.account:hover {
  border-color: var(--app-brand);
  background: #f2f6ff;
}

.account strong {
  font-size: 12.5px;
}

.account span {
  font-size: 11.5px;
  color: var(--app-muted);
}

.account em {
  font-style: normal;
  font-size: 11px;
  color: #9aa3ae;
}

@media (max-width: 900px) {
  .login-page {
    grid-template-columns: 1fr;
  }

  .intro {
    display: none;
  }

  .panel {
    min-height: 100vh;
  }
}
</style>
