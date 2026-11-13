<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

function goHome(): void {
  router.replace({ name: 'dashboard' })
}

async function switchAccount(): Promise<void> {
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="error-page">
    <div class="code">403</div>
    <h2>没有访问权限</h2>
    <p class="text-muted">
      当前账号（{{ auth.displayName }}）缺少该页面的权限码，请联系管理员分配，或换个账号登录。
    </p>
    <div class="actions">
      <el-button type="primary" @click="goHome">返回首页</el-button>
      <el-button @click="switchAccount">切换账号</el-button>
    </div>
  </div>
</template>

<style scoped>
.error-page {
  max-width: 460px;
  margin: 10vh auto 0;
  padding: 40px 28px;
  text-align: center;
  background: #fff;
  border: 1px solid var(--app-border);
  border-radius: var(--app-radius);
}

.code {
  font-size: 56px;
  font-weight: 700;
  letter-spacing: 4px;
  color: #f59e0b;
  line-height: 1;
}

h2 {
  margin: 14px 0 8px;
  font-size: 18px;
}

p {
  margin: 0 0 22px;
  font-size: 13px;
  line-height: 1.7;
}

.actions {
  display: flex;
  justify-content: center;
  gap: 10px;
}
</style>
