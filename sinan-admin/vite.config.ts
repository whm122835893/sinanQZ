import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// 司南艺术 · 管理后台工程配置
export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },
  css: {
    preprocessorOptions: {
      scss: {
        // 全局注入司南红设计令牌（仅变量/mixin，不导出样式）
        additionalData: `@use "@/assets/styles/variables.scss" as *;\n`
      }
    }
  },
  server: {
    host: true,
    port: process.env.PORT ? Number(process.env.PORT) : 5174,
    strictPort: false,
    allowedHosts: true,
    // /admin 代理到 ThinkPHP 后端多应用（app/admin）
    proxy: {
      '/admin': {
        target: process.env.VITE_PROXY_TARGET || 'http://127.0.0.1:8000',
        changeOrigin: true
      }
    }
  }
})
