import { defineStore } from 'pinia'

// 全局 UI 状态：响应式断点（移动端 TabBar / 桌面侧边栏）
export const useAppStore = defineStore('app', {
  state: () => ({
    isMobile: false
  }),
  actions: {
    initResponsive() {
      const mq = window.matchMedia('(max-width: 768px)')
      this.isMobile = mq.matches
      mq.addEventListener('change', (e) => { this.isMobile = e.matches })
    }
  }
})
