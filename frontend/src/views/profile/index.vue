<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from '@/plugins/element'
import { useAuthStore } from '@/stores/auth'
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
        </el-card>
      </el-col>
    </el-row>
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

code.mono {
  background: #f3f4f6;
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 12px;
}
</style>
