import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    // 开发期把 /api 代理到 Hyperf，避免跨域与 cookie/头处理差异
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:9501',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    target: 'esnext',
    chunkSizeWarningLimit: 900,
    rollupOptions: {
      output: {
        // 第三方库单独成包：业务代码变动时不必重新下载依赖
        manualChunks(id: string) {
          if (!id.includes('node_modules')) {
            return undefined
          }
          if (id.includes('element-plus') || id.includes('@element-plus')) {
            return 'element-plus'
          }
          if (id.includes('/vue/') || id.includes('@vue/') || id.includes('vue-router') || id.includes('pinia')) {
            return 'vue-vendor'
          }
          return 'vendor'
        },
      },
    },
  },
})
