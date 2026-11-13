<script setup lang="ts">
import { useRouter } from 'vue-router'

const router = useRouter()

function goHome(): void {
  router.replace('/')
}
</script>

<template>
  <div class="error-page">
    <div class="code">404</div>
    <h2>页面不存在</h2>
    <p class="text-muted">你访问的地址没有对应页面，可能是链接过期或输入有误。</p>
    <el-button type="primary" @click="goHome">回到首页</el-button>
  </div>
</template>

<style scoped>
.error-page {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 40px 20px;
  text-align: center;
  background: var(--app-bg);
}

.code {
  font-size: 64px;
  font-weight: 700;
  letter-spacing: 4px;
  color: #94a3b8;
  line-height: 1;
}

h2 {
  margin: 14px 0 8px;
  font-size: 18px;
}

p {
  margin: 0 0 22px;
  font-size: 13px;
}
</style>
