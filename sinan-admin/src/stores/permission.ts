// ============================================================================
// 权限路由状态：按权限码过滤动态路由并生成侧边栏菜单
// ============================================================================
import { defineStore } from 'pinia'
import type { RouteRecordRaw } from 'vue-router'
import { DYNAMIC_ROUTES } from '@/router/dynamicRoutes'
import { useAdminStore } from '@/stores/admin'

/** 递归过滤路由：无权限的整棵子树剔除 */
function filterRoutes(routes: RouteRecordRaw[], permissions: string[]): RouteRecordRaw[] {
  const result: RouteRecordRaw[] = []
  for (const route of routes) {
    const meta = route.meta ?? {}
    const perm = (meta as { permission?: string }).permission
    if (perm && !permissions.includes(perm)) {
      continue
    }
    if (route.children && route.children.length > 0) {
      const children = filterRoutes(route.children, permissions)
      if (children.length === 0 && !route.component) {
        // 子路由全被剔除的空目录不保留
        continue
      }
      result.push({ ...route, children })
    } else {
      result.push({ ...route })
    }
  }
  return result
}

export const usePermissionStore = defineStore('permission', {
  state: () => ({
    routes: [] as RouteRecordRaw[],
    loaded: false
  }),

  getters: {
    /**
     * 侧边栏菜单（顶级路由；hidden 不渲染）
     * 根布局路由（无 title，如 '/'）会被展开，其 children 提升为顶级菜单
     */
    menus(state): RouteRecordRaw[] {
      const visible = state.routes.filter((r) => !(r.meta as { hidden?: boolean })?.hidden)
      const out: RouteRecordRaw[] = []
      for (const route of visible) {
        const meta = route.meta ?? {}
        if (!meta.title && route.children && route.children.length > 0) {
          out.push(...route.children.filter((c) => !(c.meta as { hidden?: boolean })?.hidden))
        } else {
          out.push(route)
        }
      }
      return out
    }
  },

  actions: {
    buildRoutes(): RouteRecordRaw[] {
      const adminStore = useAdminStore()
      this.routes = filterRoutes(DYNAMIC_ROUTES, adminStore.permissions)
      this.loaded = true
      return this.routes
    },

    reset(): void {
      this.routes = []
      this.loaded = false
    }
  }
})
