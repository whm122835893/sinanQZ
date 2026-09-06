import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// 司南珍藏 · 管理后台工程配置（与 C 端同源的构建约定）
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
        // 全局注入设计令牌与 mixin（不导出具体样式，仅变量/函数）
        additionalData: `@use "@/styles/variables.scss" as *;\n@use "@/styles/mixins.scss" as *;\n`
      }
    }
  },
  server: {
    host: true,
    port: process.env.PORT ? Number(process.env.PORT) : 5174,
    strictPort: false,
    allowedHosts: true,
    // 联调阶段：/api 代理到 ThinkPHP 后端 admin 模块
    proxy: {
      '/api': {
        target: process.env.VITE_PROXY_TARGET || 'http://127.0.0.1:8000',
        changeOrigin: true
      }
    }
  }
})
