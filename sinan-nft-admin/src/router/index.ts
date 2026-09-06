/**
 * 路由（静态表 + 权限守卫）
 *
 * - 菜单顺序/分组由后端 menus 下发（服务端驱动侧边栏）
 * - 本地表负责 path → 视图组件 与 页面级权限码绑定
 * - 权限不足时跳 403 页，避免白屏
 */
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

// 布局
import Layout from '@/layout/index.vue'

// 视图（懒加载）
const views = import.meta.glob('@/views/**/*.vue')

function view(path: string) {
  const mod = views[`/src/views/${path}.vue`]
  if (!mod) {
    // 未知页面统一落到 404，避免构建期硬失败
    return () => import('@/views/error/404.vue')
  }
  return mod as any
}

/**
 * 业务路由表（title 供面包屑/页签使用）
 * permission 与后端 nft_admin_permissions.code 一一对应
 */
const businessRoutes: RouteRecordRaw[] = [
  { path: 'dashboard', name: 'Dashboard', component: view('dashboard/Index'), meta: { title: '数据大盘', permission: 'dashboard:view' } },

  { path: 'user', name: 'UserList', component: view('user/List'), meta: { title: '用户管理', permission: 'user:list' } },
  { path: 'user/:id', name: 'UserDetail', component: view('user/Detail'), meta: { title: '用户详情', permission: 'user:detail', hidden: true } },

  { path: 'realname', name: 'RealnameList', component: view('realname/List'), meta: { title: '实名认证', permission: 'realname:list' } },

  { path: 'collectible', name: 'CollectibleList', component: view('collectible/List'), meta: { title: '藏品管理', permission: 'collectible:list' } },
  { path: 'collectible/:id', name: 'CollectibleDetail', component: view('collectible/Detail'), meta: { title: '藏品详情', permission: 'collectible:detail', hidden: true } },

  { path: 'blindbox', name: 'BlindboxList', component: view('blindbox/List'), meta: { title: '盲盒管理', permission: 'blindbox:list' } },
  { path: 'blindbox/:id', name: 'BlindboxDetail', component: view('blindbox/Detail'), meta: { title: '盲盒详情', permission: 'blindbox:detail', hidden: true } },

  { path: 'order', name: 'OrderList', component: view('order/List'), meta: { title: '订单管理', permission: 'order:list' } },
  { path: 'order/:id', name: 'OrderDetail', component: view('order/Detail'), meta: { title: '订单详情', permission: 'order:detail', hidden: true } },

  { path: 'refund', name: 'RefundList', component: view('refund/List'), meta: { title: '退款管理', permission: 'refund:list' } },
  { path: 'market', name: 'MarketList', component: view('market/List'), meta: { title: '市场寄售', permission: 'market:list' } },
  { path: 'transfer', name: 'TransferList', component: view('transfer/List'), meta: { title: '转赠管理', permission: 'transfer:list' } },

  { path: 'marketing/priority', name: 'MarketingPriority', component: view('marketing/Priority'), meta: { title: '优先购活动', permission: 'marketing:priority:list' } },
  { path: 'marketing/checkin', name: 'MarketingCheckin', component: view('marketing/Checkin'), meta: { title: '签到活动', permission: 'marketing:checkin:config' } },
  { path: 'marketing/invite', name: 'MarketingInvite', component: view('marketing/Invite'), meta: { title: '邀请活动', permission: 'marketing:invite:config' } },
  { path: 'marketing/lucky-draw', name: 'MarketingLucky', component: view('marketing/LuckyDraw'), meta: { title: '抽奖活动', permission: 'marketing:lucky:list' } },
  { path: 'marketing/synthesis', name: 'MarketingSynthesis', component: view('marketing/Synthesis'), meta: { title: '合成活动', permission: 'marketing:synthesis:list' } },
  { path: 'marketing/airdrop', name: 'MarketingAirdrop', component: view('marketing/Airdrop'), meta: { title: '活动空投', permission: 'marketing:airdrop' } },
  { path: 'marketing/register', name: 'MarketingRegister', component: view('marketing/Register'), meta: { title: '注册福利', permission: 'marketing:register:config' } },

  { path: 'wallet/transaction', name: 'WalletTransaction', component: view('wallet/Transaction'), meta: { title: '交易记录', permission: 'wallet:transaction' } },
  { path: 'wallet/recharge', name: 'WalletRecharge', component: view('wallet/Recharge'), meta: { title: '充值记录', permission: 'wallet:recharge' } },
  { path: 'wallet/fee', name: 'WalletFee', component: view('wallet/Fee'), meta: { title: '手续费统计', permission: 'wallet:fee' } },
  { path: 'wallet/abnormal', name: 'WalletAbnormal', component: view('wallet/Abnormal'), meta: { title: '异常资金监控', permission: 'wallet:monitor' } },

  { path: 'cms/banner', name: 'CmsBanner', component: view('cms/Banner'), meta: { title: '轮播图管理', permission: 'cms:banner' } },
  { path: 'cms/announcement', name: 'CmsAnnouncement', component: view('cms/Announcement'), meta: { title: '公告管理', permission: 'cms:announcement' } },
  { path: 'cms/agreement', name: 'CmsAgreement', component: view('cms/Agreement'), meta: { title: '协议管理', permission: 'cms:agreement' } },
  { path: 'cms/artifact', name: 'CmsArtifact', component: view('cms/Artifact'), meta: { title: '文物展馆', permission: 'cms:artifact' } },
  { path: 'cms/decoration', name: 'CmsDecoration', component: view('cms/Decoration'), meta: { title: '站点装修', permission: 'cms:decoration' } },

  { path: 'system/global', name: 'SystemGlobal', component: view('system/Global'), meta: { title: '全局参数', permission: 'system:config' } },
  { path: 'system/payment', name: 'SystemPayment', component: view('system/Payment'), meta: { title: '支付渠道配置', permission: 'system:payment' } },
  { path: 'system/sms', name: 'SystemSms', component: view('system/Sms'), meta: { title: '短信配置', permission: 'system:sms' } },
  { path: 'system/security', name: 'SystemSecurity', component: view('system/Security'), meta: { title: '安全策略', permission: 'system:security' } },

  { path: 'permission/admin', name: 'PermissionAdmin', component: view('permission/Admin'), meta: { title: '管理员管理', permission: 'permission:admin' } },
  { path: 'permission/role', name: 'PermissionRole', component: view('permission/Role'), meta: { title: '角色管理', permission: 'permission:role' } },
  { path: 'permission/operation-log', name: 'PermissionLog', component: view('permission/OperationLog'), meta: { title: '操作/登录日志', permission: 'permission:log' } },

  { path: 'security/blacklist', name: 'SecurityBlacklist', component: view('security/Blacklist'), meta: { title: '黑名单', permission: 'security:blacklist' } },
  { path: 'security/risk-alert', name: 'SecurityRiskAlert', component: view('security/RiskAlert'), meta: { title: '风控告警', permission: 'security:alert' } },
  { path: 'security/event', name: 'SecurityEvent', component: view('security/Event'), meta: { title: '安全事件', permission: 'security:event' } },

  { path: 'ticket', name: 'TicketList', component: view('ticket/List'), meta: { title: '客服工单', permission: 'ticket:list' } },

  { path: 'report/sales', name: 'ReportSales', component: view('report/Sales'), meta: { title: '销售报表', permission: 'report:sales' } },
  { path: 'report/user', name: 'ReportUser', component: view('report/User'), meta: { title: '用户报表', permission: 'report:user' } },
  { path: 'report/collectible', name: 'ReportCollectible', component: view('report/Collectible'), meta: { title: '藏品报表', permission: 'report:collectible' } },
  { path: 'report/blindbox', name: 'ReportBlindbox', component: view('report/Blindbox'), meta: { title: '盲盒报表', permission: 'report:blindbox' } },
  { path: 'report/finance', name: 'ReportFinance', component: view('report/Finance'), meta: { title: '财务对账', permission: 'report:finance' } },

  { path: 'platform/logs', name: 'PlatformLogs', component: view('platform/Logs'), meta: { title: '平台运维', permission: 'platform:log' } },

  { path: 'profile', name: 'Profile', component: view('profile/Index'), meta: { title: '个人中心', hidden: true } },
]

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'Login',
      component: view('login/Index'),
      meta: { title: '登录', public: true },
    },
    {
      path: '/403',
      name: 'Forbidden',
      component: view('error/403'),
      meta: { title: '无权限', public: true },
    },
    {
      path: '/404',
      name: 'NotFound',
      component: view('error/404'),
      meta: { title: '页面不存在', public: true },
    },
    {
      path: '/',
      component: Layout,
      redirect: '/dashboard',
      children: businessRoutes,
    },
    { path: '/:pathMatch(.*)*', redirect: '/404' },
  ],
})

// 全局守卫：登录校验 + 权限校验 + 标题
router.beforeEach((to) => {
  const auth = useAuthStore()
  document.title = to.meta.title ? `${to.meta.title} · 司南数字藏品后台` : '司南数字藏品后台'

  if (to.meta.public) {
    // 已登录访问登录页 → 回首页
    if (to.name === 'Login' && auth.isLoggedIn) return '/dashboard'
    return true
  }

  if (!auth.isLoggedIn) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  const permission = to.meta.permission as string | undefined
  if (permission && !auth.hasPermission(permission)) {
    return { path: '/403', query: { permission } }
  }
  return true
})

export default router
