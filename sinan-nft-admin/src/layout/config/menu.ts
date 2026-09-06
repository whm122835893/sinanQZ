/**
 * 侧边栏菜单配置（本地静态表 + 服务端权限过滤）
 *
 * 设计说明：
 * - 菜单结构与后端 nft_admin_permissions(type=1) 的 path 约定一一对应
 * - 是否显示由服务端下发的权限码决定（auth.hasPermission）
 * - 详情页（/user/:id 等含参数路由）不出现在菜单，仅路由可达
 */
export interface MenuNode {
  title: string
  path: string
  code: string
  icon: string
  children?: MenuNode[]
}

export const menuConfig: MenuNode[] = [
  { title: '数据大盘', path: '/dashboard', code: 'dashboard:view', icon: 'Odometer' },

  { title: '用户管理', path: '/user', code: 'user:list', icon: 'User' },
  { title: '实名认证', path: '/realname', code: 'realname:list', icon: 'UserFilled' },

  { title: '藏品管理', path: '/collectible', code: 'collectible:list', icon: 'Collection' },
  { title: '盲盒管理', path: '/blindbox', code: 'blindbox:list', icon: 'Box' },

  { title: '订单管理', path: '/order', code: 'order:list', icon: 'Document' },
  { title: '退款管理', path: '/refund', code: 'refund:list', icon: 'Money' },
  { title: '市场寄售', path: '/market', code: 'market:list', icon: 'Shop' },
  { title: '转赠管理', path: '/transfer', code: 'transfer:list', icon: 'Share' },

  {
    title: '营销活动',
    path: '/marketing',
    code: 'marketing:priority:list',
    icon: 'Present',
    children: [
      { title: '优先购活动', path: '/marketing/priority', code: 'marketing:priority:list', icon: 'Clock' },
      { title: '签到活动', path: '/marketing/checkin', code: 'marketing:checkin:config', icon: 'Calendar' },
      { title: '邀请活动', path: '/marketing/invite', code: 'marketing:invite:config', icon: 'Promotion' },
      { title: '抽奖活动', path: '/marketing/lucky-draw', code: 'marketing:lucky:list', icon: 'Trophy' },
      { title: '合成活动', path: '/marketing/synthesis', code: 'marketing:synthesis:list', icon: 'Connection' },
      { title: '活动空投', path: '/marketing/airdrop', code: 'marketing:airdrop', icon: 'Bell' },
      { title: '注册福利', path: '/marketing/register', code: 'marketing:register:config', icon: 'Gift' },
    ],
  },

  {
    title: '钱包财务',
    path: '/wallet',
    code: 'wallet:transaction',
    icon: 'Wallet',
    children: [
      { title: '交易记录', path: '/wallet/transaction', code: 'wallet:transaction', icon: 'Tickets' },
      { title: '充值记录', path: '/wallet/recharge', code: 'wallet:recharge', icon: 'CreditCard' },
      { title: '手续费统计', path: '/wallet/fee', code: 'wallet:fee', icon: 'Coin' },
      { title: '异常资金监控', path: '/wallet/abnormal', code: 'wallet:monitor', icon: 'Warning' },
    ],
  },

  {
    title: '内容管理',
    path: '/cms',
    code: 'cms:banner',
    icon: 'Picture',
    children: [
      { title: '轮播图管理', path: '/cms/banner', code: 'cms:banner', icon: 'PictureFilled' },
      { title: '公告管理', path: '/cms/announcement', code: 'cms:announcement', icon: 'Notification' },
      { title: '协议管理', path: '/cms/agreement', code: 'cms:agreement', icon: 'Document' },
      { title: '文物展馆', path: '/cms/artifact', code: 'cms:artifact', icon: 'OfficeBuilding' },
      { title: '站点装修', path: '/cms/decoration', code: 'cms:decoration', icon: 'Brush' },
    ],
  },

  {
    title: '系统配置',
    path: '/system',
    code: 'system:config',
    icon: 'Setting',
    children: [
      { title: '全局参数', path: '/system/global', code: 'system:config', icon: 'Tools' },
      { title: '支付渠道配置', path: '/system/payment', code: 'system:payment', icon: 'CreditCard' },
      { title: '短信配置', path: '/system/sms', code: 'system:sms', icon: 'Message' },
      { title: '安全策略', path: '/system/security', code: 'system:security', icon: 'Lock' },
    ],
  },

  {
    title: '权限管理',
    path: '/permission',
    code: 'permission:admin',
    icon: 'Key',
    children: [
      { title: '管理员管理', path: '/permission/admin', code: 'permission:admin', icon: 'Avatar' },
      { title: '角色管理', path: '/permission/role', code: 'permission:role', icon: 'UserFilled' },
      { title: '操作/登录日志', path: '/permission/operation-log', code: 'permission:log', icon: 'Notebook' },
    ],
  },

  {
    title: '风控安全',
    path: '/security',
    code: 'security:blacklist',
    icon: 'Warning',
    children: [
      { title: '黑名单', path: '/security/blacklist', code: 'security:blacklist', icon: 'CircleClose' },
      { title: '风控告警', path: '/security/risk-alert', code: 'security:alert', icon: 'AlarmClock' },
      { title: '安全事件', path: '/security/event', code: 'security:event', icon: 'Failed' },
    ],
  },

  { title: '客服工单', path: '/ticket', code: 'ticket:list', icon: 'Service' },

  {
    title: '数据报表',
    path: '/report',
    code: 'report:sales',
    icon: 'TrendCharts',
    children: [
      { title: '销售报表', path: '/report/sales', code: 'report:sales', icon: 'Histogram' },
      { title: '用户报表', path: '/report/user', code: 'report:user', icon: 'User' },
      { title: '藏品报表', path: '/report/collectible', code: 'report:collectible', icon: 'Collection' },
      { title: '盲盒报表', path: '/report/blindbox', code: 'report:blindbox', icon: 'Box' },
      { title: '财务对账', path: '/report/finance', code: 'report:finance', icon: 'Coin' },
    ],
  },

  { title: '平台运维', path: '/platform/logs', code: 'platform:log', icon: 'Delete' },
]
