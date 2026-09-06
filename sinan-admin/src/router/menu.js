// ============================================================
// 侧边栏 / 权限菜单配置（BuildAdmin 式「菜单即路由」声明）
// 每项含 perm 权限码，侧栏按管理员 permissions 过滤渲染；
// 联调后可替换为后端下发的动态菜单
// ============================================================

export const menuGroups = [
  {
    group: '总览',
    items: [
      { path: '/dashboard', title: '数据看板', icon: 'Odometer', perm: 'dashboard' },
      { path: '/statistics', title: '数据统计', icon: 'TrendCharts', perm: 'statistics' }
    ]
  },
  {
    group: '用户',
    items: [
      { path: '/user', title: '用户管理', icon: 'User', perm: 'user' },
      { path: '/user/realname', title: '实名审核', icon: 'Stamp', perm: 'realname' }
    ]
  },
  {
    group: '藏品',
    items: [
      { path: '/collectible', title: '藏品管理', icon: 'Picture', perm: 'collectible' },
      { path: '/blindbox', title: '盲盒管理', icon: 'Gift', perm: 'blindbox' }
    ]
  },
  {
    group: '交易',
    items: [
      { path: '/order', title: '订单管理', icon: 'List', perm: 'order' },
      { path: '/order/refunds', title: '退款管理', icon: 'Money', perm: 'refund' },
      { path: '/resale', title: '寄售市场', icon: 'Sell', perm: 'resale' },
      { path: '/transfer', title: '转赠管理', icon: 'Position', perm: 'transfer' }
    ]
  },
  {
    group: '营销',
    items: [
      { path: '/marketing', title: '营销中心', icon: 'Present', perm: 'marketing' },
      { path: '/marketing/checkin', title: '签到配置', icon: 'Calendar', perm: 'marketing' },
      { path: '/marketing/luckydraw', title: '抽奖活动', icon: 'Trophy', perm: 'marketing' },
      { path: '/marketing/synthesis', title: '合成活动', icon: 'MagicStick', perm: 'marketing' },
      { path: '/marketing/invite', title: '邀请活动', icon: 'Share', perm: 'marketing' },
      { path: '/marketing/priority', title: '优先购管理', icon: 'Timer', perm: 'marketing' },
      { path: '/marketing/qualification', title: '资格购管理', icon: 'Key', perm: 'marketing' }
    ]
  },
  {
    group: '资产',
    items: [
      { path: '/wallet', title: '钱包流水', icon: 'Wallet', perm: 'wallet' }
    ]
  },
  {
    group: '风控',
    items: [
      { path: '/risk', title: '风控告警', icon: 'Warning', perm: 'risk' },
      { path: '/tickets', title: '客服工单', icon: 'Service', perm: 'tickets' }
    ]
  },
  {
    group: '内容',
    items: [
      { path: '/content/announcements', title: '公告管理', icon: 'Bell', perm: 'content' },
      { path: '/content/banners', title: '轮播管理', icon: 'PictureFilled', perm: 'content' },
      { path: '/content/community', title: '社区管理', icon: 'ChatDotRound', perm: 'content' },
      { path: '/content/artifacts', title: '文物展馆', icon: 'OfficeBuilding', perm: 'content' },
      { path: '/content/audits', title: '内容审核', icon: 'View', perm: 'content' }
    ]
  },
  {
    group: '区块链',
    items: [
      { path: '/chain', title: '链上交互', icon: 'Link', perm: 'chain' }
    ]
  },
  {
    group: '系统',
    items: [
      { path: '/system/admins', title: '管理员', icon: 'UserFilled', perm: 'system' },
      { path: '/system/logs', title: '操作日志', icon: 'Document', perm: 'system' },
      { path: '/system/approvals', title: '审批中心', icon: 'Stamp', perm: 'system' },
      { path: '/system/config', title: '站点配置', icon: 'Setting', perm: 'system' },
      { path: '/system/cleanup', title: '平台清库', icon: 'Delete', perm: 'cleanup' }
    ]
  }
]
