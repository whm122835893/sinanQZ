// ============================================================================
// 标签页状态：多页签导航（可关闭 / 右键菜单 P1 扩展）
// ============================================================================
import { defineStore } from 'pinia'

export interface TagView {
  path: string
  title: string
  name?: string
  affix?: boolean
}

export const useAppStore = defineStore('app', {
  state: () => ({
    sidebarFolded: false,
    visitedViews: [] as TagView[]
  }),

  actions: {
    toggleSidebar(): void {
      this.sidebarFolded = !this.sidebarFolded
    },

    addVisitedView(view: TagView): void {
      if (this.visitedViews.some((v) => v.path === view.path)) return
      this.visitedViews.push({ ...view })
    },

    removeVisitedView(path: string): TagView[] {
      const idx = this.visitedViews.findIndex((v) => v.path === path)
      if (idx > -1) {
        this.visitedViews.splice(idx, 1)
      }
      return this.visitedViews
    },

    reset(): void {
      this.sidebarFolded = false
      this.visitedViews = []
    }
  }
})
