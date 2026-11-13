<script setup lang="ts">
import { RouterView } from 'vue-router'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
</script>

<template>
  <!-- 全局中文语言包：分页、空数据、日期等文案 -->
  <el-config-provider :locale="zhCn">
    <RouterView />
  </el-config-provider>
</template>
