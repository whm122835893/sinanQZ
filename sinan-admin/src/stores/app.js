import { defineStore } from 'pinia'

// 全局 UI 状态：响应式断点 / 侧栏折叠 / 多标签页
export const useAppStore = defineStore('app', {
  state: () => ({
    isMobile: false,
    sidebarCollapsed: false,
    drawerOpen: false,          // 移动端侧栏抽屉
    visitedTags: []             // [{ path, title }]
  }),
  actions: {
    initResponsive() {
      const mq = window.matchMedia('(max-width: 768px)')
      this.isMobile = mq.matches
      mq.addEventListener('change', (e) => { this.isMobile = e.matches })
    },
    toggleSidebar() {
      this.sidebarCollapsed = !this.sidebarCollapsed
    },
    toggleDrawer(open) {
      this.drawerOpen = open ?? !this.drawerOpen
    },
    // ---- 多标签页 ----
    addTag(route) {
      if (!route.path || route.meta?.public) return
      if (this.visitedTags.some((t) => t.path === route.path)) return
      this.visitedTags.push({ path: route.path, title: route.meta?.title || route.path })
    },
    removeTag(path) {
      const idx = this.visitedTags.findIndex((t) => t.path === path)
      if (idx > -1) this.visitedTags.splice(idx, 1)
      return this.visitedTags[Math.max(0, idx - 1)]?.path || '/dashboard'
    },
    removeOtherTags(path) {
      this.visitedTags = this.visitedTags.filter((t) => t.path === path)
    },
    removeAllTags() {
      this.visitedTags = []
    }
  }
})
