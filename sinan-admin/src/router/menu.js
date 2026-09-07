// ============================================================
// 侧边栏 / 权限菜单配置（平铺分组：分组标题 + 一级菜单项）
// icon 为分组图标，二级菜单模式下折叠大组标题使用
// 每项含 perm 权限码，侧栏按管理员 permissions 过滤渲染
// ============================================================

export const menuGroups = [
  {
    group: '总览',
    icon: 'Monitor',
    items: [
      { path: '/dashboard', title: '数据看板', icon: 'Odometer', perm: 'dashboard' },
      { path: '/statistics', title: '数据统计', icon: 'TrendCharts', perm: 'statistics' }
    ]
  },
  {
    group: '用户',
    icon: 'Avatar',
    items: [
      { path: '/user', title: '用户管理', icon: 'User', perm: 'user' },
      { path: '/user/realname', title: '实名审核', icon: 'Stamp', perm: 'realname' }
    ]
  },
  {
    group: '藏品管理',
    icon: 'Goods',
    items: [
      { path: '/collectible', title: '藏品列表', icon: 'Picture', perm: 'collectible' },
      { path: '/blindbox', title: '盲盒管理', icon: 'Gift', perm: 'blindbox' },
      { path: '/marketing/priority', title: '优先购管理', icon: 'Timer', perm: 'marketing' },
      { path: '/marketing/qualification', title: '资格购管理', icon: 'Key', perm: 'marketing' },
      { path: '/marketing/raffle', title: '抽签购管理', icon: 'Tickets', perm: 'marketing' }
    ]
  },
  {
    group: '活动管理',
    icon: 'Present',
    items: [
      { path: '/marketing/invite', title: '邀请活动', icon: 'Share', perm: 'marketing' },
      { path: '/marketing/register', title: '注册活动', icon: 'Stamp', perm: 'marketing' },
      { path: '/marketing/checkin', title: '签到活动', icon: 'Calendar', perm: 'marketing' },
      { path: '/marketing/luckydraw', title: '抽奖活动', icon: 'Trophy', perm: 'marketing' },
      { path: '/marketing/synthesis', title: '合成活动', icon: 'MagicStick', perm: 'marketing' },
      { path: '/marketing/decompose', title: '分解活动', icon: 'Aim', perm: 'marketing' },
      { path: '/marketing/reward-records', title: '奖励名单', icon: 'Memo', perm: 'marketing' }
    ]
  },
  {
    group: '交易',
    icon: 'Coin',
    items: [
      { path: '/order', title: '订单管理', icon: 'List', perm: 'order' },
      { path: '/order/refunds', title: '退款管理', icon: 'Money', perm: 'refund' },
      { path: '/resale', title: '寄售市场', icon: 'Sell', perm: 'resale' },
      { path: '/transfer', title: '转赠管理', icon: 'Position', perm: 'transfer' },
      { path: '/buy-request', title: '求购挂单', icon: 'ShoppingCart', perm: 'buyrequest' },
      { path: '/swap', title: '置换管理', icon: 'Refresh', perm: 'swap' }
    ]
  },
  {
    group: '资产',
    icon: 'Wallet',
    items: [
      { path: '/wallet', title: '钱包流水', icon: 'Wallet', perm: 'wallet' }
    ]
  },
  {
    group: '风控',
    icon: 'WarningFilled',
    items: [
      { path: '/risk', title: '风控告警', icon: 'Warning', perm: 'risk' },
      { path: '/tickets', title: '客服工单', icon: 'Service', perm: 'tickets' }
    ]
  },
  {
    group: '内容',
    icon: 'Reading',
    items: [
      { path: '/content/announcements', title: '公告管理', icon: 'Bell', perm: 'content' },
      { path: '/content/banners', title: '轮播管理', icon: 'PictureFilled', perm: 'content' },
      { path: '/content/community', title: '社区管理', icon: 'ChatDotRound', perm: 'content' },
      { path: '/content/artifacts', title: '文物展馆', icon: 'OfficeBuilding', perm: 'content' },
      { path: '/content/decoration', title: '站点装修', icon: 'Brush', perm: 'content' }
    ]
  },
  {
    group: '区块链',
    icon: 'Link',
    items: [
      { path: '/chain', title: '链上交互', icon: 'Link', perm: 'chain' }
    ]
  },
  {
    group: '系统',
    icon: 'Setting',
    items: [
      { path: '/system/admins', title: '管理员', icon: 'UserFilled', perm: 'system' },
      { path: '/system/logs', title: '操作日志', icon: 'Document', perm: 'system' },
      { path: '/system/approvals', title: '审批中心', icon: 'Stamp', perm: 'system' },
      { path: '/system/audits', title: '数据审计', icon: 'DataAnalysis', perm: 'system' },
      { path: '/system/snapshot', title: '数据快照', icon: 'Camera', perm: 'report.snapshot' },
      { path: '/system/config', title: '全局参数', icon: 'Setting', perm: 'system' },
      { path: '/system/sms', title: '短信配置', icon: 'Message', perm: 'system' },
      { path: '/system/payment', title: '支付渠道', icon: 'CreditCard', perm: 'system' },
      { path: '/system/security', title: '安全策略', icon: 'Lock', perm: 'system' },
      { path: '/system/cleanup', title: '平台清库', icon: 'Delete', perm: 'cleanup' },
      { path: '/system/trash', title: '回收站', icon: 'DeleteFilled', perm: 'trash' }
    ]
  }
]
