// ============================================================================
// 路由：静态路由 + 动态权限路由注入 + 守卫（未登录跳登录页）
// ============================================================================
import { createRouter, createWebHashHistory, type RouteLocationNormalized } from 'vue-router'
import type { RouteRecordRaw } from 'vue-router'
import { useAdminStore } from '@/stores/admin'
import { usePermissionStore } from '@/stores/permission'
import { useAppStore } from '@/stores/app'
import { fetchMe } from '@/api/auth'

/** 静态路由（无权限要求） */
export const staticRoutes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/login/Index.vue'),
    meta: { title: '登录' }
  },
  {
    path: '/403',
    name: '403',
    component: () => import('@/views/error/Forbidden.vue'),
    meta: { title: '无权限' }
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/error/NotFound.vue'),
    meta: { title: '页面不存在', hidden: true }
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes: staticRoutes
})

/** 白名单 */
const WHITE_LIST = ['/login', '/403']

router.beforeEach(async (to: RouteLocationNormalized) => {
  document.title = to.meta.title ? `${to.meta.title} · 司南管理后台` : '司南管理后台'

  const adminStore = useAdminStore()
  const permissionStore = usePermissionStore()

  // 未登录 → 登录页（带回跳）
  if (!adminStore.token) {
    if (to.path === '/login' || WHITE_LIST.includes(to.path)) {
      return true
    }
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  // 已登录访问登录页 → 回首页
  if (to.path === '/login') {
    return { path: '/' }
  }

  // 动态路由已加载
  if (permissionStore.loaded) {
    return true
  }

  // 拉取管理员信息并注入动态路由
  try {
    const me = await fetchMe()
    adminStore.setMe(me.admin, me.permissions)
    const routes = permissionStore.buildRoutes()
    for (const route of routes) {
      router.addRoute(route)
    }
    return { ...to, replace: true }
  } catch {
    adminStore.reset()
    permissionStore.reset()
    return { path: '/login', query: { redirect: to.fullPath } }
  }
})

/** 记录标签页 */
router.afterEach((to) => {
  if (to.name && !['login', 'not-found', '403'].includes(String(to.name))) {
    const appStore = useAppStore()
    appStore.addVisitedView({
      path: to.path,
      title: to.meta.title ?? '未命名',
      name: String(to.name),
      affix: !!to.meta.affix
    })
  }
})

export default router
