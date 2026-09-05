import { createRouter, createWebHashHistory } from 'vue-router'
import { useAdminStore } from '@/stores/admin'

// ============================================================
// 司南珍藏 · 管理后台路由（Hash 模式，与 C 端一致）
// meta: { title 页面标题 / tab 移动端主 Tab / public 免登录 }
// ============================================================

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/login/Index.vue'),
    meta: { title: '登录', public: true }
  },
  {
    path: '/',
    component: () => import('@/layouts/AdminLayout.vue'),
    children: [
      { path: '', redirect: '/dashboard' },

      // ---- 总览 ----
      { path: 'dashboard', name: 'dashboard', component: () => import('@/views/dashboard/Index.vue'), meta: { title: '数据看板', tab: true } },

      // ---- 用户 ----
      { path: 'user', name: 'user', component: () => import('@/views/user/Index.vue'), meta: { title: '用户管理' } },
      { path: 'user/realname', name: 'realname', component: () => import('@/views/user/Realname.vue'), meta: { title: '实名审核' } },

      // ---- 藏品 ----
      { path: 'collectible', name: 'collectible', component: () => import('@/views/collectible/Index.vue'), meta: { title: '藏品管理', tab: true } },
      { path: 'collectible/detail/:id', name: 'collectible-detail', component: () => import('@/views/collectible/Detail.vue'), meta: { title: '藏品详情' } },
      { path: 'collectible/edit/:id?', name: 'collectible-edit', component: () => import('@/views/collectible/Edit.vue'), meta: { title: '编辑藏品' } },
      { path: 'blindbox', name: 'blindbox', component: () => import('@/views/blindbox/Index.vue'), meta: { title: '盲盒管理' } },
      { path: 'blindbox/detail/:id', name: 'blindbox-detail', component: () => import('@/views/blindbox/Detail.vue'), meta: { title: '盲盒配置' } },

      // ---- 交易 ----
      { path: 'order', name: 'order', component: () => import('@/views/order/Index.vue'), meta: { title: '订单管理', tab: true } },
      { path: 'order/refunds', name: 'refunds', component: () => import('@/views/order/Refunds.vue'), meta: { title: '退款管理' } },
      { path: 'resale', name: 'resale', component: () => import('@/views/resale/Index.vue'), meta: { title: '寄售管理' } },
      { path: 'transfer', name: 'transfer', component: () => import('@/views/transfer/Index.vue'), meta: { title: '转赠管理' } },

      // ---- 营销 ----
      { path: 'marketing', name: 'marketing', component: () => import('@/views/marketing/Index.vue'), meta: { title: '营销中心', tab: true } },
      { path: 'marketing/checkin', name: 'checkin', component: () => import('@/views/marketing/CheckIn.vue'), meta: { title: '签到配置' } },
      { path: 'marketing/luckydraw', name: 'luckydraw', component: () => import('@/views/marketing/LuckyDraw.vue'), meta: { title: '抽奖活动' } },
      { path: 'marketing/synthesis', name: 'synthesis', component: () => import('@/views/marketing/Synthesis.vue'), meta: { title: '合成活动' } },
      { path: 'marketing/invite', name: 'invite', component: () => import('@/views/marketing/Invite.vue'), meta: { title: '邀请活动' } },
      { path: 'marketing/priority', name: 'priority', component: () => import('@/views/marketing/Priority.vue'), meta: { title: '优先购管理' } },

      // ---- 资产 ----
      { path: 'wallet', name: 'wallet', component: () => import('@/views/wallet/Index.vue'), meta: { title: '钱包流水' } },

      // ---- 内容 ----
      { path: 'content/announcements', name: 'announcements', component: () => import('@/views/content/Announcements.vue'), meta: { title: '公告管理' } },
      { path: 'content/banners', name: 'banners', component: () => import('@/views/content/Banners.vue'), meta: { title: '轮播管理' } },
      { path: 'content/community', name: 'community', component: () => import('@/views/content/Community.vue'), meta: { title: '社区管理' } },
      { path: 'content/artifacts', name: 'artifacts', component: () => import('@/views/content/Artifacts.vue'), meta: { title: '文物展馆' } },

      // ---- 系统 ----
      { path: 'system/admins', name: 'admins', component: () => import('@/views/system/Admins.vue'), meta: { title: '管理员' } },
      { path: 'system/logs', name: 'logs', component: () => import('@/views/system/Logs.vue'), meta: { title: '操作日志' } },
      { path: 'system/config', name: 'config', component: () => import('@/views/system/Config.vue'), meta: { title: '站点配置' } },

      // ---- 管理中枢（移动端第 5 Tab）----
      { path: 'mine', name: 'mine', component: () => import('@/views/mine/Index.vue'), meta: { title: '管理中枢', tab: true } },

      // ---- 兜底 ----
      { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('@/views/error/NotFound.vue'), meta: { title: '页面不存在' } }
    ]
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 }
  }
})

// 登录守卫（联调时对接后端 JWT 校验）
router.beforeEach((to) => {
  const admin = useAdminStore()
  if (to.meta.public !== true && !admin.isLogged) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  if (to.path === '/login' && admin.isLogged) {
    return '/dashboard'
  }
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · 司南珍藏管理后台` : '司南珍藏管理后台'
})

export default router
