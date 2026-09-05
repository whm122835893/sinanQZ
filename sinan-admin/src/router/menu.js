// ============================================================
// 侧边栏 / 权限菜单配置（BuildAdmin 式「菜单即路由」声明）
// 联调后可替换为后端下发的动态菜单
// ============================================================

export const menuGroups = [
  {
    group: '总览',
    items: [
      { path: '/dashboard', title: '数据看板', icon: 'bar-chart-o' }
    ]
  },
  {
    group: '用户',
    items: [
      { path: '/user', title: '用户管理', icon: 'friends-o' },
      { path: '/user/realname', title: '实名审核', icon: 'shield-o' }
    ]
  },
  {
    group: '藏品',
    items: [
      { path: '/collectible', title: '藏品管理', icon: 'gem-o' },
      { path: '/blindbox', title: '盲盒管理', icon: 'gift-o' }
    ]
  },
  {
    group: '交易',
    items: [
      { path: '/order', title: '订单管理', icon: 'orders-o' },
      { path: '/order/refunds', title: '退款管理', icon: 'refund-o' },
      { path: '/resale', title: '寄售管理', icon: 'exchange' },
      { path: '/transfer', title: '转赠管理', icon: 'logistics' }
    ]
  },
  {
    group: '营销',
    items: [
      { path: '/marketing', title: '营销中心', icon: 'points' },
      { path: '/marketing/checkin', title: '签到配置', icon: 'calendar-o' },
      { path: '/marketing/luckydraw', title: '抽奖活动', icon: 'medal-o' },
      { path: '/marketing/synthesis', title: '合成活动', icon: 'cluster-o' },
      { path: '/marketing/invite', title: '邀请活动', icon: 'contact' },
      { path: '/marketing/priority', title: '优先购管理', icon: 'coupon-o' }
    ]
  },
  {
    group: '资产',
    items: [
      { path: '/wallet', title: '钱包流水', icon: 'balance-o' }
    ]
  },
  {
    group: '内容',
    items: [
      { path: '/content/announcements', title: '公告管理', icon: 'bell' },
      { path: '/content/banners', title: '轮播管理', icon: 'photo-o' },
      { path: '/content/community', title: '社区管理', icon: 'chat-o' },
      { path: '/content/artifacts', title: '文物展馆', icon: 'shop-o' }
    ]
  },
  {
    group: '系统',
    items: [
      { path: '/system/admins', title: '管理员', icon: 'manager-o' },
      { path: '/system/logs', title: '操作日志', icon: 'records' },
      { path: '/system/config', title: '站点配置', icon: 'setting-o' }
    ]
  }
]

// 移动端底部主 Tab（与 C 端 TabBar 同构：中间为圆形平台 logo）
export const mainTabs = [
  { name: 'dashboard', label: '看板', to: '/dashboard', icon: 'bar-chart-o' },
  { name: 'collectible', label: '藏品', to: '/collectible', icon: 'gem-o' },
  { name: 'marketing', label: '活动', to: '/marketing', round: true, hideLabel: true },
  { name: 'order', label: '订单', to: '/order', icon: 'orders-o' },
  { name: 'mine', label: '更多', to: '/mine', icon: 'apps-o' }
]
