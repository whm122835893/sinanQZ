import { createRouter, createWebHashHistory } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useAdminStore } from '@/stores/admin'

// ============================================================
// 司南珍藏 · 管理后台路由（Hash 模式）
// meta: { title 页面标题 / perm 权限码 / public 免登录 }
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
      { path: 'dashboard', name: 'dashboard', component: () => import('@/views/dashboard/Index.vue'), meta: { title: '数据看板', perm: 'dashboard' } },
      { path: 'statistics', name: 'statistics', component: () => import('@/views/statistics/Index.vue'), meta: { title: '数据统计', perm: 'statistics' } },

      // ---- 用户 ----
      { path: 'user', name: 'user', component: () => import('@/views/user/Index.vue'), meta: { title: '用户管理', perm: 'user' } },
      { path: 'user/realname', name: 'realname', component: () => import('@/views/user/Realname.vue'), meta: { title: '实名审核', perm: 'realname' } },

      // ---- 藏品 ----
      { path: 'collectible', name: 'collectible', component: () => import('@/views/collectible/Index.vue'), meta: { title: '藏品管理', perm: 'collectible' } },
      { path: 'collectible/detail/:id', name: 'collectible-detail', component: () => import('@/views/collectible/Detail.vue'), meta: { title: '藏品详情', perm: 'collectible' } },
      { path: 'collectible/edit/:id?', name: 'collectible-edit', component: () => import('@/views/collectible/Edit.vue'), meta: { title: '编辑藏品', perm: 'collectible' } },
      { path: 'blindbox', name: 'blindbox', component: () => import('@/views/blindbox/Index.vue'), meta: { title: '盲盒管理', perm: 'blindbox' } },
      { path: 'blindbox/detail/:id', name: 'blindbox-detail', component: () => import('@/views/blindbox/Detail.vue'), meta: { title: '盲盒配置', perm: 'blindbox' } },
      { path: 'blindbox/edit/:id?', name: 'blindbox-edit', component: () => import('@/views/blindbox/Edit.vue'), meta: { title: '编辑盲盒', perm: 'blindbox' } },

      // ---- 交易 ----
      { path: 'order', name: 'order', component: () => import('@/views/order/Index.vue'), meta: { title: '订单管理', perm: 'order' } },
      { path: 'order/refunds', name: 'refunds', component: () => import('@/views/order/Refunds.vue'), meta: { title: '退款管理', perm: 'refund' } },
      { path: 'resale', name: 'resale', component: () => import('@/views/resale/Index.vue'), meta: { title: '寄售市场', perm: 'resale' } },
      { path: 'transfer', name: 'transfer', component: () => import('@/views/transfer/Index.vue'), meta: { title: '转赠管理', perm: 'transfer' } },

      // ---- 营销 ----
      { path: 'marketing', name: 'marketing', component: () => import('@/views/marketing/Index.vue'), meta: { title: '营销中心', perm: 'marketing' } },
      { path: 'marketing/checkin', name: 'checkin', component: () => import('@/views/marketing/CheckIn.vue'), meta: { title: '签到配置', perm: 'marketing' } },
      { path: 'marketing/luckydraw', name: 'luckydraw', component: () => import('@/views/marketing/LuckyDraw.vue'), meta: { title: '抽奖活动', perm: 'marketing' } },
      { path: 'marketing/synthesis', name: 'synthesis', component: () => import('@/views/marketing/Synthesis.vue'), meta: { title: '合成活动', perm: 'marketing' } },
      { path: 'marketing/invite', name: 'invite', component: () => import('@/views/marketing/Invite.vue'), meta: { title: '邀请活动', perm: 'marketing' } },
      { path: 'marketing/priority', name: 'priority', component: () => import('@/views/marketing/Priority.vue'), meta: { title: '优先购管理', perm: 'marketing' } },
      { path: 'marketing/qualification', name: 'qualification', component: () => import('@/views/marketing/Qualification.vue'), meta: { title: '资格购管理', perm: 'marketing' } },

      // ---- 资产 ----
      { path: 'wallet', name: 'wallet', component: () => import('@/views/wallet/Index.vue'), meta: { title: '钱包流水', perm: 'wallet' } },

      // ---- 风控 ----
      { path: 'risk', name: 'risk', component: () => import('@/views/risk/Index.vue'), meta: { title: '风控告警', perm: 'risk' } },
      { path: 'tickets', name: 'tickets', component: () => import('@/views/tickets/Index.vue'), meta: { title: '客服工单', perm: 'tickets' } },

      // ---- 内容 ----
      { path: 'content/announcements', name: 'announcements', component: () => import('@/views/content/Announcements.vue'), meta: { title: '公告管理', perm: 'content' } },
      { path: 'content/banners', name: 'banners', component: () => import('@/views/content/Banners.vue'), meta: { title: '轮播管理', perm: 'content' } },
      { path: 'content/community', name: 'community', component: () => import('@/views/content/Community.vue'), meta: { title: '社区管理', perm: 'content' } },
      { path: 'content/artifacts', name: 'artifacts', component: () => import('@/views/content/Artifacts.vue'), meta: { title: '文物展馆', perm: 'content' } },
      { path: 'content/audits', name: 'content-audits', component: () => import('@/views/content/Audits.vue'), meta: { title: '内容审核', perm: 'content' } },

      // ---- 区块链 ----
      { path: 'chain', name: 'chain', component: () => import('@/views/chain/Index.vue'), meta: { title: '链上交互', perm: 'chain' } },

      // ---- 系统 ----
      { path: 'system/admins', name: 'admins', component: () => import('@/views/system/Admins.vue'), meta: { title: '管理员', perm: 'system' } },
      { path: 'system/logs', name: 'logs', component: () => import('@/views/system/Logs.vue'), meta: { title: '操作日志', perm: 'system' } },
      { path: 'system/approvals', name: 'approvals', component: () => import('@/views/system/Approvals.vue'), meta: { title: '审批中心', perm: 'system' } },
      { path: 'system/config', name: 'config', component: () => import('@/views/system/Config.vue'), meta: { title: '站点配置', perm: 'system' } },
      { path: 'system/cleanup', name: 'cleanup', component: () => import('@/views/system/Cleanup.vue'), meta: { title: '平台清库', perm: 'cleanup' } },

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

// 登录守卫 + 权限校验（联调时对接后端 JWT）
router.beforeEach((to) => {
  const admin = useAdminStore()
  if (to.meta.public !== true && !admin.isLogged) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  if (to.path === '/login' && admin.isLogged) {
    return '/dashboard'
  }
  // 权限码校验：无权限跳看板并提示
  if (to.meta.perm && !admin.hasPermission(to.meta.perm)) {
    ElMessage.error('暂无访问权限（403）')
    return to.path === '/dashboard' ? false : '/dashboard'
  }
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · 司南珍藏管理后台` : '司南珍藏管理后台'
})

export default router
