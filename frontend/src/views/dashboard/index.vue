<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { Refresh } from '@element-plus/icons-vue'
import { ElMessage } from '@/plugins/element'
import { useAuthStore } from '@/stores/auth'
import { API_BASE_URL } from '@/api/http'
import { getExpiresAt } from '@/utils/token'

const auth = useAuthStore()
const router = useRouter()

const now = ref(Date.now())
const refreshing = ref(false)
let timer: number | undefined

onMounted(() => {
  timer = window.setInterval(() => {
    now.value = Date.now()
  }, 1000)
})

onBeforeUnmount(() => {
  if (timer) {
    window.clearInterval(timer)
  }
})

const remainingSeconds = computed(() => {
  const expiresAt = getExpiresAt()
  if (!expiresAt) {
    return 0
  }
  return Math.max(0, Math.floor((expiresAt - now.value) / 1000))
})

const remainingText = computed(() => {
  const total = remainingSeconds.value
  const hours = Math.floor(total / 3600)
  const minutes = Math.floor((total % 3600) / 60)
  const seconds = total % 60
  return [hours, minutes, seconds].map((value) => String(value).padStart(2, '0')).join(':')
})

const expiringSoon = computed(() => remainingSeconds.value > 0 && remainingSeconds.value < 300)

const roleLabel = computed(() => (auth.user?.role === 'admin' ? '管理员' : '普通用户'))

async function reloadProfile(): Promise<void> {
  refreshing.value = true
  try {
    await auth.fetchProfile(true)
    ElMessage.success('用户信息已刷新')
  } catch {
    ElMessage.error('刷新失败，登录态可能已失效')
  } finally {
    refreshing.value = false
  }
}

function goAudit(): void {
  router.push({ name: 'audit' })
}
</script>

<template>
  <div class="page">
    <div class="page-header">
      <div>
        <h2>你好，{{ auth.displayName }}</h2>
        <p class="sub">这是基于 Hyperf 认证接口的示例后台，可作为后续业务的起点。</p>
      </div>
      <el-button :loading="refreshing" @click="reloadProfile">
        <el-icon><Refresh /></el-icon>
        <span>刷新资料</span>
      </el-button>
    </div>

    <el-row :gutter="16">
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="card">
          <div class="card-label">当前角色</div>
          <div class="card-value">{{ roleLabel }}</div>
          <el-tag size="small" type="info" class="mono">{{ auth.user?.role }}</el-tag>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="card">
          <div class="card-label">已授予权限</div>
          <div class="card-value">{{ auth.permissions.length }} 项</div>
          <el-tag size="small" type="success" class="mono">permissions[]</el-tag>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="card">
          <div class="card-label">Access Token 剩余</div>
          <div class="card-value mono" :class="{ warn: expiringSoon }">{{ remainingText }}</div>
          <el-tag size="small" :type="expiringSoon ? 'warning' : 'success'">
            {{ expiringSoon ? '即将自动刷新' : '有效' }}
          </el-tag>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :md="6">
        <el-card shadow="never" class="card">
          <div class="card-label">请求前缀</div>
          <div class="card-value mono small">{{ API_BASE_URL }}</div>
          <el-tag size="small" type="info">vite proxy → 9501</el-tag>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16">
      <el-col :xs="24" :md="12">
        <el-card shadow="never" class="block">
          <template #header>
            <div class="block-head">
              <span>我的权限</span>
              <span class="text-muted mono">{{ auth.user?.roles.join(', ') }}</span>
            </div>
          </template>

          <div v-if="auth.permissions.length" class="tags">
            <el-tag v-for="code in auth.permissions" :key="code" class="mono" type="success" effect="plain">
              {{ code }}
            </el-tag>
          </div>
          <el-empty v-else description="该账号没有任何权限码" :image-size="70" />

          <el-divider content-position="left">
            <span class="divider-text">按钮级权限演示</span>
          </el-divider>

          <div class="demo-row">
            <el-button v-permission="'user.manage'" type="primary" plain>
              仅 user.manage 可见
            </el-button>
            <el-button v-permission="'audit.view'" plain @click="goAudit">进入登录审计</el-button>
            <span class="text-muted demo-note">
              无权限的按钮已被 <code>v-permission</code> 指令移除
            </span>
          </div>
        </el-card>
      </el-col>

      <el-col :xs="24" :md="12">
        <el-card shadow="never" class="block">
          <template #header>
            <div class="block-head"><span>账号信息</span></div>
          </template>

          <el-descriptions :column="1" size="small" border>
            <el-descriptions-item label="用户 ID">{{ auth.user?.id }}</el-descriptions-item>
            <el-descriptions-item label="用户名">{{ auth.user?.username }}</el-descriptions-item>
            <el-descriptions-item label="昵称">{{ auth.user?.nickname || '—' }}</el-descriptions-item>
            <el-descriptions-item label="邮箱">{{ auth.user?.email || '—' }}</el-descriptions-item>
            <el-descriptions-item label="上次登录">{{ auth.user?.last_login_at || '—' }}</el-descriptions-item>
            <el-descriptions-item label="创建时间">{{ auth.user?.created_at || '—' }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never" class="block">
      <template #header>
        <div class="block-head"><span>下一步可以做什么</span></div>
      </template>

      <div class="steps">
        <div class="step">
          <div class="step-index">1</div>
          <div>
            <strong>按业务建表</strong>
            <p>在 Hyperf 里加 migration / Model / Controller，前端在 <code>src/api</code> 补一层调用即可。</p>
          </div>
        </div>
        <div class="step">
          <div class="step-index">2</div>
          <div>
            <strong>加页面</strong>
            <p>在 <code>src/router/routes.ts</code> 里加一条路由并写上 <code>meta.permission</code>，菜单会自动出现。</p>
          </div>
        </div>
        <div class="step">
          <div class="step-index">3</div>
          <div>
            <strong>接权限</strong>
            <p>后端把角色写进 <code>users.role</code> 即可；后续要做多角色/权限表，只需替换 <code>App\Support\Permission</code>。</p>
          </div>
        </div>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.card {
  border-radius: var(--app-radius);
  margin-bottom: 16px;
}

.card-label {
  font-size: 12px;
  color: var(--app-muted);
}

.card-value {
  font-size: 22px;
  font-weight: 650;
  margin: 6px 0 8px;
  line-height: 1.2;
}

.card-value.small {
  font-size: 16px;
}

.card-value.warn {
  color: #d97706;
}

.block {
  border-radius: var(--app-radius);
  margin-bottom: 16px;
}

.block-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 14px;
  font-weight: 600;
}

.tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.divider-text {
  font-size: 12px;
  color: var(--app-muted);
}

.demo-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.demo-note {
  font-size: 12px;
}

.demo-note code {
  background: #f3f4f6;
  padding: 1px 5px;
  border-radius: 4px;
}

.steps {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
}

.step {
  display: flex;
  gap: 12px;
}

.step-index {
  width: 24px;
  height: 24px;
  flex: none;
  border-radius: 50%;
  background: #eef2ff;
  color: var(--app-brand);
  font-size: 12px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
}

.step strong {
  font-size: 13px;
}

.step p {
  margin: 4px 0 0;
  font-size: 12.5px;
  color: var(--app-muted);
  line-height: 1.6;
}

.step code {
  background: #f3f4f6;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 12px;
}
</style>
