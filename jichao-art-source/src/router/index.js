import { createRouter, createWebHashHistory } from 'vue-router'

// ============================================================
// 司南艺术 路由表（Hash 模式，适配 H5）
// meta.tabbar: 该页面底部固定显示 5 个主 Tab
// ============================================================

const routes = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/Home.vue'),
    meta: { title: '司南艺术', tabbar: true }
  },
  {
    path: '/market',
    component: () => import('@/views/Market.vue'),
    redirect: '/market/activity',
    meta: { title: '市场', tabbar: true },
    children: [
      { path: 'following', name: 'market-following', component: () => import('@/views/market/MarketFollowing.vue'), meta: { title: '我的关注' } },
      { path: 'activity', name: 'market-activity', component: () => import('@/views/market/MarketActivity.vue'), meta: { title: '活动市场' } },
      { path: 'free', name: 'market-free', component: () => import('@/views/market/MarketFree.vue'), meta: { title: '自由市场' } }
    ]
  },
  {
    path: '/mall',
    name: 'mall',
    component: () => import('@/views/Mall.vue'),
    meta: { title: '商城', tabbar: true }
  },
  {
    path: '/notice',
    name: 'notice',
    component: () => import('@/views/Notice.vue'),
    meta: { title: '公告列表', tabbar: true }
  },
  {
    path: '/notice/:id',
    name: 'notice-detail',
    component: () => import('@/views/NoticeDetail.vue'),
    meta: { title: '公告详情' }
  },
  {
    path: '/user',
    name: 'user',
    component: () => import('@/views/User.vue'),
    meta: { title: '我的', tabbar: true }
  },
  // ---- 用户中心子页 ----
  { path: '/user/profile', name: 'profile', component: () => import('@/views/user/Profile.vue'), meta: { title: '个人信息' } },
  { path: '/user/security', name: 'security', component: () => import('@/views/user/Security.vue'), meta: { title: '账户安全' } },
  { path: '/user/realname', name: 'realname', component: () => import('@/views/user/Realname.vue'), meta: { title: '实名认证' } },
  { path: '/user/collections', name: 'collections', component: () => import('@/views/user/Collections.vue'), meta: { title: '我的藏品' } },
  { path: '/user/wallet', name: 'wallet', component: () => import('@/views/user/Wallet.vue'), meta: { title: '我的钱包' } },
  { path: '/user/orders', name: 'orders', component: () => import('@/views/user/Orders.vue'), meta: { title: '我的订单' } },
  { path: '/user/purchase', name: 'purchase', component: () => import('@/views/user/Purchase.vue'), meta: { title: '转赠记录' } },
  { path: '/user/invite', name: 'invite', component: () => import('@/views/user/Invite.vue'), meta: { title: '我的好友' } },
  { path: '/user/community', name: 'community', component: () => import('@/views/user/Community.vue'), meta: { title: '加入社区' } },
  { path: '/user/service', name: 'service', component: () => import('@/views/user/Service.vue'), meta: { title: '我的客服' } },
  // ---- 鉴权页 ----
  { path: '/auth/login', name: 'login', component: () => import('@/views/auth/Login.vue'), meta: { title: '登录' } },
  { path: '/auth/register', name: 'register', component: () => import('@/views/auth/Register.vue'), meta: { title: '注册' } },
  { path: '/auth/forgot', name: 'forgot', component: () => import('@/views/auth/Forgot.vue'), meta: { title: '找回密码' } },
  { path: '/auth/change-pwd', name: 'change-pwd', component: () => import('@/views/auth/ChangePwd.vue'), meta: { title: '修改密码' } },
  { path: '/auth/op-pwd', name: 'op-pwd', component: () => import('@/views/auth/OpPwd.vue'), meta: { title: '操作密码' } },
  { path: '/auth/cancel', name: 'cancel', component: () => import('@/views/auth/Cancel.vue'), meta: { title: '注销账号' } },
  // ---- 业务页 ----
  { path: '/collection/:id', name: 'collection-detail', component: () => import('@/views/CollectionDetail.vue'), meta: { title: '藏品详情' } },
  { path: '/resale/:id', name: 'resale-detail', component: () => import('@/views/Resale.vue'), meta: { title: '寄售挂单' } },
  { path: '/resale-order/:id/:no', name: 'resale-order', component: () => import('@/views/ResaleOrder.vue'), meta: { title: '挂单详情' } },
  // 支付页：发售支付 / 挂单支付 合并为单路由，用 mode 区分，互不串跳
  { path: '/pay/:mode/:id/:no?', name: 'pay', component: () => import('@/views/Pay.vue'), meta: { title: '支付订单' } },
  { path: '/calendar', name: 'calendar', component: () => import('@/views/Calendar.vue'), meta: { title: '司南·首发日历' } },
  { path: '/activity', name: 'activity', component: () => import('@/views/Activity.vue'), meta: { title: '活动' } },
  { path: '/activity/synthesis/:id', name: 'activity-synthesis', component: () => import('@/views/Synthesis.vue'), meta: { title: '合成活动' } },
  { path: '/lottery', name: 'lottery', component: () => import('@/views/Lottery.vue'), meta: { title: '司南·抽奖' } },
  // ---- 兜底 ----
  { path: '/:pathMatch(.*)*', redirect: '/' }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior() {
    return { top: 0 }
  }
})

export default router
