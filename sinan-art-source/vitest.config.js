import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config.js'

// 复用应用的 alias / scss 设计令牌注入，避免测试配置与 vite.config.js 走偏
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      include: ['tests/**/*.spec.js']
    }
  })
)
