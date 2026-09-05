// ============================================================
// 司南珍藏 · 管理后台 Mock 数据库
// 联调阶段：所有 API 均读取此内存库；接入真实后端后可整体移除。
// 数据与 C 端业务（藏品/盲盒/订单/寄售/转赠/签到/抽奖/合成/邀请）
// 及常规数字藏品后台（库存/配额/空投/销毁/审计）保持同构。
// ============================================================

const DAY = 864e5
const now = Date.now()

// n 天前的时间戳
const ts = (days, hours = 0) => now - days * DAY - hours * 36e5
// n 天后
const tsA = (days, hours = 0) => now + days * DAY + hours * 36e5

const pad = (n) => String(n).padStart(2, '0')
// 格式化为 'YYYY-MM-DD HH:mm'
const dt = (t) => {
  const d = new Date(t)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}
const dOnly = (t) => {
  const d = new Date(t)
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

// ---------------- 管理员 ----------------
export const admins = [
  { id: 1, username: 'admin', name: '超级管理员', role: 'super', avatar: '/images/platform-logo.png', status: 'enabled', lastLoginTime: dt(ts(0, 1)), phone: '13800000001', twofaEnabled: 1, ipWhitelist: '192.168.1.100' },
  { id: 2, username: 'wangyun', name: '王运营', role: 'operator', avatar: '/images/avatar-new.png', status: 'enabled', lastLoginTime: dt(ts(0, 5)), phone: '13800000002', twofaEnabled: 0, ipWhitelist: '' },
  { id: 3, username: 'lisheg', name: '李风控', role: 'risk', avatar: '/images/avatar-new.png', status: 'enabled', lastLoginTime: dt(ts(1, 2)), phone: '13800000003', twofaEnabled: 1, ipWhitelist: '' },
  { id: 4, username: 'qiancai', name: '钱财务', role: 'finance', avatar: '/images/avatar-new.png', status: 'enabled', lastLoginTime: dt(ts(0, 8)), phone: '13800000004', twofaEnabled: 1, ipWhitelist: '' },
  { id: 5, username: 'sukefu', name: '苏客服', role: 'support', avatar: '/images/avatar-new.png', status: 'disabled', lastLoginTime: dt(ts(12)), phone: '13800000005', twofaEnabled: 0, ipWhitelist: '' }
]

// 5 角色权限体系（与 stores/admin.js ROLE_PERMISSIONS 同构）
export const roles = [
  { id: 1, key: 'super', name: '超级管理员', desc: '全部功能，含平台清库、完整实名查看、所有高风险操作', members: 1, permissions: ['*'] },
  { id: 2, key: 'operator', name: '运营专员', desc: '藏品/盲盒/活动/CMS/基础用户管理/订单查看', members: 1, permissions: ['dashboard', 'statistics', 'user', 'user.freeze', 'collectible', 'blindbox', 'order', 'resale', 'transfer', 'marketing', 'content'] },
  { id: 3, key: 'finance', name: '财务专员', desc: '订单/钱包流水/财务报表/手续费统计/收支导出', members: 1, permissions: ['dashboard', 'statistics', 'order', 'refund', 'wallet'] },
  { id: 4, key: 'risk', name: '风控专员', desc: '黑名单/异常交易审批/风控告警/实名完整查看', members: 1, permissions: ['dashboard', 'user', 'user.blacklist', 'user.recover', 'realname', 'realname.full', 'risk', 'resale', 'transfer'] },
  { id: 5, key: 'support', name: '客服专员', desc: '工单处理/基础用户查询（仅脱敏）/用户资产查看', members: 1, permissions: ['dashboard', 'tickets', 'user'] }
]

export const permissionTree = [
  { label: '数据看板', key: 'dashboard' },
  { label: '用户管理', key: 'user' },
  { label: '实名审核', key: 'user.realname' },
  { label: '藏品管理', key: 'collectible' },
  { label: '盲盒管理', key: 'blindbox' },
  { label: '订单管理', key: 'order' },
  { label: '退款管理', key: 'order.refund' },
  { label: '寄售管理', key: 'resale' },
  { label: '转赠管理', key: 'transfer' },
  { label: '营销活动', key: 'marketing' },
  { label: '钱包流水', key: 'wallet' },
  { label: '内容管理', key: 'content' },
  { label: '系统管理', key: 'system' }
]

// ---------------- 用户 ----------------
export const users = [
  { id: 1001, nickname: '罗盘先生', phone: '13812340001', avatar: '/images/avatar-new.png', registerTime: dt(ts(120, 3)), balance: 2860.5, points: 12400, collectibleCount: 23, orderCount: 15, status: 'normal', realnameStatus: 'approved', realnameName: '陈司南', realnameIdNo: '330106199001010011', lastLoginTime: dt(ts(0, 2)) },
  { id: 1002, nickname: '青铜爱好者', phone: '13812340002', avatar: '/images/avatar-new.png', registerTime: dt(ts(96, 8)), balance: 540, points: 3210, collectibleCount: 11, orderCount: 8, status: 'normal', realnameStatus: 'approved', realnameName: '刘锡朋', realnameIdNo: '440304199203154022', lastLoginTime: dt(ts(0, 6)) },
  { id: 1003, nickname: '司南藏友8823', phone: '13812340003', avatar: '/images/avatar-new.png', registerTime: dt(ts(64, 2)), balance: 68.5, points: 980, collectibleCount: 4, orderCount: 3, status: 'normal', realnameStatus: 'pending', realnameName: '赵无极', realnameIdNo: '110105199506128017', lastLoginTime: dt(ts(1, 1)) },
  { id: 1004, nickname: '山海经考据党', phone: '13812340004', avatar: '/images/avatar-new.png', registerTime: dt(ts(58, 5)), balance: 1230, points: 5600, collectibleCount: 9, orderCount: 7, status: 'normal', realnameStatus: 'approved', realnameName: '孙墨白', realnameIdNo: '32010219910720333X', lastLoginTime: dt(ts(0, 9)) },
  { id: 1005, nickname: '长卷收卷人', phone: '13812340005', avatar: '/images/avatar-new.png', registerTime: dt(ts(45, 1)), balance: 89.9, points: 640, collectibleCount: 2, orderCount: 2, status: 'frozen', realnameStatus: 'rejected', realnameName: '周慕云', realnameIdNo: '510104198812110518', lastLoginTime: dt(ts(6, 3)) },
  { id: 1006, nickname: '织锦护膊', phone: '13812340006', avatar: '/images/avatar-new.png', registerTime: dt(ts(39, 6)), balance: 3200, points: 15200, collectibleCount: 31, orderCount: 19, status: 'normal', realnameStatus: 'approved', realnameName: '吴青霞', realnameIdNo: '610103199001019024', lastLoginTime: dt(ts(0, 12)) },
  { id: 1007, nickname: '洛神赋吟者', phone: '13812340007', avatar: '/images/avatar-new.png', registerTime: dt(ts(33, 4)), balance: 456.6, points: 2100, collectibleCount: 6, orderCount: 5, status: 'normal', realnameStatus: 'none', lastLoginTime: dt(ts(2, 7)) },
  { id: 1008, nickname: '虎符将军', phone: '13812340008', avatar: '/images/avatar-new.png', registerTime: dt(ts(28, 9)), balance: 990, points: 4400, collectibleCount: 8, orderCount: 6, status: 'normal', realnameStatus: 'approved', realnameName: '郑千钧', realnameIdNo: '420106199409236612', lastLoginTime: dt(ts(1, 4)) },
  { id: 1009, nickname: '异兽图鉴', phone: '13812340009', avatar: '/images/avatar-new.png', registerTime: dt(ts(21, 2)), balance: 12, points: 260, collectibleCount: 1, orderCount: 1, status: 'normal', realnameStatus: 'pending', realnameName: '冯虚御', realnameIdNo: '350203199604175210', lastLoginTime: dt(ts(3, 1)) },
  { id: 1010, nickname: '星轨观测员', phone: '13812340010', avatar: '/images/avatar-new.png', registerTime: dt(ts(14, 7)), balance: 780, points: 1500, collectibleCount: 3, orderCount: 2, status: 'normal', realnameStatus: 'approved', realnameName: '卫临风', realnameIdNo: '130102199208128713', lastLoginTime: dt(ts(0, 20)) },
  { id: 1011, nickname: '金错刀', phone: '13812340011', avatar: '/images/avatar-new.png', registerTime: dt(ts(9, 5)), balance: 260, points: 700, collectibleCount: 2, orderCount: 1, status: 'normal', realnameStatus: 'none', lastLoginTime: dt(ts(4, 2)) },
  { id: 1012, nickname: '面具收藏家', phone: '13812340012', avatar: '/images/avatar-new.png', registerTime: dt(ts(3, 1)), balance: 199, points: 199, collectibleCount: 1, orderCount: 1, status: 'normal', realnameStatus: 'pending', realnameName: '沈未央', realnameIdNo: '500103199902114026', lastLoginTime: dt(ts(0, 3)) }
]

// ---------------- 藏品 ----------------
// 库存池 = edition - sold - locked - reserved - airdropped - destroyed
export const collectibles = [
  { id: 9001, name: '龙纹罗盘', subtitle: '司南珍藏系列', cover: '/images/collections/cover-1.jpg', category: '青铜', price: 399, edition: 1000, sold: 932, lockedQuantity: 6, reservedCount: 50, airdroppedCount: 30, destroyedCount: 2, circulate: 962, status: 'onsale', tag: '首发', issuer: '司南数字藏品', saleTime: dt(ts(10, 3)), description: '以战国司南为原型，盘面铸有精密方位刻度，象征导航智慧。', featured: true, isBlindBox: false },
  { id: 9002, name: '云端法器', subtitle: '司南珍藏系列', cover: '/images/collections/cover-2.jpg', category: '国潮', price: 299, edition: 800, sold: 692, lockedQuantity: 2, reservedCount: 30, airdroppedCount: 16, destroyedCount: 0, circulate: 708, status: 'onsale', tag: '首发', issuer: '司南数字藏品', saleTime: dt(ts(8, 5)), description: '取意道家云纹法器，鎏金渐变工艺呈现。', featured: true, isBlindBox: false },
  { id: 9003, name: '青铜面具', subtitle: '司南珍藏系列', cover: '/images/collections/cover-3.jpg', category: '国潮', price: 199, edition: 600, sold: 510, lockedQuantity: 0, reservedCount: 40, airdroppedCount: 22, destroyedCount: 0, circulate: 532, status: 'onsale', tag: '热销', issuer: '司南数字藏品', saleTime: dt(ts(6, 2)), description: '三星堆鎏金面具数字化再现，威严而神秘。', featured: false, isBlindBox: false },
  { id: 9004, name: '司南秘宝盲盒', subtitle: '开启得随机限定藏品', cover: '/images/collections/cover-collection-bb1.jpg', category: '盲盒', price: 99, edition: 5000, sold: 4812, lockedQuantity: 0, reservedCount: 0, airdroppedCount: 12, destroyedCount: 8, circulate: 4824, status: 'onsale', tag: '盲盒', issuer: '司南数字藏品', saleTime: dt(ts(15, 4)), description: '购买后可开启，随机获得限定藏品一份。', featured: true, isBlindBox: true },
  { id: 9005, name: '司南·星轨徽章', subtitle: '观星台联名款', cover: '/images/collections/cover-4.jpg', category: '限定', price: 129, edition: 2000, sold: 0, lockedQuantity: 0, reservedCount: 100, airdroppedCount: 0, destroyedCount: 0, circulate: 0, status: 'upcoming', tag: '预告', issuer: '司南数字藏品', saleTime: dt(tsA(2, 6)), description: '以古观星图为蓝本的星轨徽章，首发倒计时中。', featured: false, isBlindBox: false },
  { id: 9006, name: '山海经·异兽图', subtitle: '水墨新绘卷', cover: '/images/collections/cover-5.jpg', category: '水墨', price: 169, edition: 1500, sold: 933, lockedQuantity: 4, reservedCount: 60, airdroppedCount: 18, destroyedCount: 0, circulate: 951, status: 'onsale', tag: '水墨', issuer: '司南数字藏品', saleTime: dt(ts(4, 8)), description: '新水墨笔法重绘九尾狐、烛龙等异兽。', featured: false, isBlindBox: false },
  { id: 9007, name: '千里江山·数字长卷', subtitle: '青绿山水再创作', cover: '/images/collections/cover-collection-1.jpg', category: '水墨', price: 499, edition: 3000, sold: 2871, lockedQuantity: 8, reservedCount: 40, airdroppedCount: 26, destroyedCount: 0, circulate: 2897, status: 'onsale', tag: '爆款', issuer: '司南数字藏品', saleTime: dt(ts(18, 6)), description: '青绿山水长卷的动态数字再创作，支持横向卷轴浏览。', featured: true, isBlindBox: false },
  { id: 9008, name: '洛神赋·绢本残卷', subtitle: '古籍再造计划', cover: '/images/collections/cover-collection-2.jpg', category: '水墨', price: 259, edition: 800, sold: 800, lockedQuantity: 0, reservedCount: 0, airdroppedCount: 0, destroyedCount: 0, circulate: 800, status: 'soldout', tag: '售罄', issuer: '司南数字藏品', saleTime: dt(ts(22, 2)), description: '曹植《洛神赋》绢本残卷的数字孪生。', featured: false, isBlindBox: false },
  { id: 9009, name: '战国错金铭文虎符', subtitle: '青铜铸造计划', cover: '/images/collections/cover-collection-3.jpg', category: '青铜', price: 899, edition: 500, sold: 452, lockedQuantity: 3, reservedCount: 20, airdroppedCount: 8, destroyedCount: 0, circulate: 460, status: 'onsale', tag: '典藏', issuer: '司南数字藏品', saleTime: dt(ts(7, 1)), description: '虎符错金铭文的三维扫描数字还原。', featured: false, isBlindBox: false },
  { id: 9010, name: '汉代织锦护膊', subtitle: '五星出东方利中国', cover: '/images/collections/cover-collection-4.jpg', category: '限定', price: 599, edition: 400, sold: 120, lockedQuantity: 0, reservedCount: 0, airdroppedCount: 0, destroyedCount: 0, circulate: 120, status: 'offline', tag: '下架', issuer: '司南数字藏品', saleTime: dt(ts(30, 3)), description: '国宝织锦护膊的数字化收藏版本。', featured: false, isBlindBox: false },
  { id: 9011, name: '司南秘宝·典藏盲盒', subtitle: '典藏概率升级', cover: '/images/collections/cover-collection-5.jpg', category: '盲盒', price: 299, edition: 2000, sold: 0, lockedQuantity: 0, reservedCount: 0, airdroppedCount: 0, destroyedCount: 0, circulate: 0, status: 'upcoming', tag: '盲盒', issuer: '司南数字藏品', saleTime: dt(tsA(5, 2)), description: '高阶典藏盲盒，稀有藏品概率大幅提升。', featured: false, isBlindBox: true }
]

// 配额（quota_type：1优先购 2活动空投 3签到 4注册 5邀请 6抽奖 7其他）
export const quotas = [
  { id: 1, collectibleId: 9001, quotaType: 1, quotaName: '龙纹罗盘·优先购预留', plannedQuantity: 50, usedQuantity: 48, status: 1, activityType: 'priority', remark: '优先购白名单' },
  { id: 2, collectibleId: 9001, quotaType: 2, quotaName: '中秋活动空投', plannedQuantity: 30, usedQuantity: 22, status: 1, activityType: 'airdrop', remark: '' },
  { id: 3, collectibleId: 9003, quotaType: 3, quotaName: '连续签到7天奖励', plannedQuantity: 40, usedQuantity: 28, status: 1, activityType: 'checkin', remark: '第30天奖励' },
  { id: 4, collectibleId: 9005, quotaType: 1, quotaName: '星轨徽章·资格购预留', plannedQuantity: 100, usedQuantity: 0, status: 1, activityType: 'qualification', remark: '' },
  { id: 5, collectibleId: 9006, quotaType: 6, quotaName: '抽奖奖品池', plannedQuantity: 60, usedQuantity: 18, status: 1, activityType: 'lucky_draw', remark: '三等奖池' },
  { id: 6, collectibleId: 9007, quotaType: 5, quotaName: '邀请活动奖励池', plannedQuantity: 40, usedQuantity: 12, status: 1, activityType: 'invite', remark: '' }
]

// ---------------- 盲盒 ----------------
export const blindBoxes = [
  {
    id: 1, collectibleId: 9004, name: '司南秘宝盲盒', cover: '/images/collections/cover-collection-bb1.jpg',
    price: 99, edition: 5000, sold: 4812, status: 'onsale', isOpenable: 1, openedCount: 4766,
    items: [
      { id: 11, prizeCollectibleId: 9001, prizeName: '龙纹罗盘', cover: '/images/collections/cover-1.jpg', probability: 0.1, quantityLimit: 5, quantityDistributed: 4 },
      { id: 12, prizeCollectibleId: 9002, prizeName: '云端法器', cover: '/images/collections/cover-2.jpg', probability: 0.2, quantityLimit: 10, quantityDistributed: 9 },
      { id: 13, prizeCollectibleId: 9003, prizeName: '青铜面具', cover: '/images/collections/cover-3.jpg', probability: 0.7, quantityLimit: null, quantityDistributed: 3386 }
    ]
  },
  {
    id: 2, collectibleId: 9011, name: '司南秘宝·典藏盲盒', cover: '/images/collections/cover-collection-5.jpg',
    price: 299, edition: 2000, sold: 0, status: 'upcoming', isOpenable: 1, openedCount: 0,
    items: [
      { id: 21, prizeCollectibleId: 9005, prizeName: '司南·星轨徽章', cover: '/images/collections/cover-4.jpg', probability: 0.05, quantityLimit: 10, quantityDistributed: 0 },
      { id: 22, prizeCollectibleId: 9009, prizeName: '战国错金铭文虎符', cover: '/images/collections/cover-collection-3.jpg', probability: 0.15, quantityLimit: 30, quantityDistributed: 0 },
      { id: 23, prizeCollectibleId: 9006, prizeName: '山海经·异兽图', cover: '/images/collections/cover-5.jpg', probability: 0.8, quantityLimit: null, quantityDistributed: 0 }
    ]
  }
]

// ---------------- 订单 ----------------
export const orders = [
  { id: 1, orderNo: 'SN20260905153201', userId: 1001, userName: '罗盘先生', userPhone: '13812340001', collectibleId: 9007, collectibleName: '千里江山·数字长卷', cover: '/images/collections/cover-collection-1.jpg', quantity: 1, unitPrice: 499, amount: 499, source: 'release', status: 'paid', payType: 'wallet', createTime: dt(ts(0, 1)), payTime: dt(ts(0, 1)) },
  { id: 2, orderNo: 'SN20260905140918', userId: 1006, userName: '织锦护膊', userPhone: '13812340006', collectibleId: 9004, collectibleName: '司南秘宝盲盒', cover: '/images/collections/cover-collection-bb1.jpg', quantity: 5, unitPrice: 99, amount: 495, source: 'blindbox', status: 'paid', payType: 'wallet', createTime: dt(ts(0, 2)), payTime: dt(ts(0, 2)) },
  { id: 3, orderNo: 'SN20260905112235', userId: 1003, userName: '司南藏友8823', userPhone: '13812340003', collectibleId: 9006, collectibleName: '山海经·异兽图', cover: '/images/collections/cover-5.jpg', quantity: 1, unitPrice: 169, amount: 169, source: 'release', status: 'pending', payType: 'wallet', createTime: dt(ts(0, 5)), payTime: null },
  { id: 4, orderNo: 'SN20260904213944', userId: 1004, userName: '山海经考据党', userPhone: '13812340004', collectibleId: 9001, collectibleName: '龙纹罗盘', cover: '/images/collections/cover-1.jpg', quantity: 1, unitPrice: 399, amount: 399, source: 'release', status: 'completed', payType: 'wallet', createTime: dt(ts(1, 3)), payTime: dt(ts(1, 3)) },
  { id: 5, orderNo: 'SN20260904200916', userId: 1008, userName: '虎符将军', userPhone: '13812340008', collectibleId: 9009, collectibleName: '战国错金铭文虎符', cover: '/images/collections/cover-collection-3.jpg', quantity: 1, unitPrice: 899, amount: 899, source: 'release', status: 'refunding', payType: 'wallet', createTime: dt(ts(1, 6)), payTime: dt(ts(1, 6)) },
  { id: 6, orderNo: 'SN20260904161752', userId: 1010, userName: '星轨观测员', userPhone: '13812340010', collectibleId: 9002, collectibleName: '云端法器', cover: '/images/collections/cover-2.jpg', quantity: 2, unitPrice: 299, amount: 598, source: 'release', status: 'abnormal', payType: 'wallet', createTime: dt(ts(1, 9)), payTime: dt(ts(1, 9)) },
  { id: 7, orderNo: 'SN20260903182231', userId: 1002, userName: '青铜爱好者', userPhone: '13812340002', collectibleId: 9003, collectibleName: '青铜面具', cover: '/images/collections/cover-3.jpg', quantity: 1, unitPrice: 199, amount: 199, source: 'priority', status: 'completed', payType: 'wallet', createTime: dt(ts(2, 4)), payTime: dt(ts(2, 4)) },
  { id: 8, orderNo: 'SN20260903150747', userId: 1012, userName: '面具收藏家', userPhone: '13812340012', collectibleId: 9003, collectibleName: '青铜面具', cover: '/images/collections/cover-3.jpg', quantity: 1, unitPrice: 199, amount: 199, source: 'eligibility', status: 'paid', payType: 'wallet', createTime: dt(ts(2, 8)), payTime: dt(ts(2, 8)) },
  { id: 9, orderNo: 'SN20260902101519', userId: 1005, userName: '长卷收卷人', userPhone: '13812340005', collectibleId: 9007, collectibleName: '千里江山·数字长卷', cover: '/images/collections/cover-collection-1.jpg', quantity: 1, unitPrice: 499, amount: 499, source: 'release', status: 'refunded', payType: 'wallet', createTime: dt(ts(3, 5)), payTime: dt(ts(3, 5)) },
  { id: 10, orderNo: 'SN20260901204403', userId: 1007, userName: '洛神赋吟者', userPhone: '13812340007', collectibleId: 9008, collectibleName: '洛神赋·绢本残卷', cover: '/images/collections/cover-collection-2.jpg', quantity: 1, unitPrice: 259, amount: 259, source: 'market', status: 'cancelled', payType: 'wallet', createTime: dt(ts(4, 2)), payTime: null },
  { id: 11, orderNo: 'SN20260831123110', userId: 1006, userName: '织锦护膊', userPhone: '13812340006', collectibleId: 9004, collectibleName: '司南秘宝盲盒', cover: '/images/collections/cover-collection-bb1.jpg', quantity: 3, unitPrice: 99, amount: 297, source: 'blindbox', status: 'completed', payType: 'wallet', createTime: dt(ts(5, 7)), payTime: dt(ts(5, 7)) },
  { id: 12, orderNo: 'SN20260830162257', userId: 1011, userName: '金错刀', userPhone: '13812340011', collectibleId: 9001, collectibleName: '龙纹罗盘', cover: '/images/collections/cover-1.jpg', quantity: 1, unitPrice: 399, amount: 399, source: 'release', status: 'pending', payType: 'wallet', createTime: dt(ts(6, 4)), payTime: null },
  { id: 13, orderNo: 'SN20260829190833', userId: 1004, userName: '山海经考据党', userPhone: '13812340004', collectibleId: 9006, collectibleName: '山海经·异兽图', cover: '/images/collections/cover-5.jpg', quantity: 1, unitPrice: 169, amount: 169, source: 'release', status: 'completed', payType: 'wallet', createTime: dt(ts(7, 2)), payTime: dt(ts(7, 2)) },
  { id: 14, orderNo: 'SN20260828110621', userId: 1009, userName: '异兽图鉴', userPhone: '13812340009', collectibleId: 9003, collectibleName: '青铜面具', cover: '/images/collections/cover-3.jpg', quantity: 1, unitPrice: 199, amount: 199, source: 'market', status: 'completed', payType: 'wallet', createTime: dt(ts(8, 6)), payTime: dt(ts(8, 6)) },
  { id: 15, orderNo: 'SN20260827142439', userId: 1002, userName: '青铜爱好者', userPhone: '13812340002', collectibleId: 9009, collectibleName: '战国错金铭文虎符', cover: '/images/collections/cover-collection-3.jpg', quantity: 1, unitPrice: 899, amount: 899, source: 'priority', status: 'completed', payType: 'wallet', createTime: dt(ts(9, 3)), payTime: dt(ts(9, 3)) },
  { id: 16, orderNo: 'SN20260827091548', userId: 1001, userName: '罗盘先生', userPhone: '13812340001', collectibleId: 9002, collectibleName: '云端法器', cover: '/images/collections/cover-2.jpg', quantity: 1, unitPrice: 299, amount: 299, source: 'eligibility', status: 'completed', payType: 'wallet', createTime: dt(ts(9, 9)), payTime: dt(ts(9, 9)) }
]

export const refunds = [
  { id: 1, orderNo: 'SN20260904200916', userId: 1008, userName: '虎符将军', userPhone: '13812340008', collectibleName: '战国错金铭文虎符', amount: 899, reason: '重复购买，误下单', status: 'pending', applyTime: dt(ts(0, 4)) },
  { id: 2, orderNo: 'SN20260902101519', userId: 1005, userName: '长卷收卷人', userPhone: '13812340005', collectibleName: '千里江山·数字长卷', amount: 499, reason: '页面显示异常申请退款', status: 'pending', applyTime: dt(ts(0, 8)) },
  { id: 3, orderNo: 'SN20260830162257', userId: 1011, userName: '金错刀', userPhone: '13812340011', collectibleName: '龙纹罗盘', amount: 399, reason: '支付后长时间未到账', status: 'approved', applyTime: dt(ts(3, 2)), handleTime: dt(ts(2, 9)) },
  { id: 4, orderNo: 'SN20260825110904', userId: 1007, userName: '洛神赋吟者', userPhone: '13812340007', collectibleName: '青铜面具', amount: 199, reason: '误用他人账号购买', status: 'rejected', applyTime: dt(ts(6, 5)), handleTime: dt(ts(6, 2)) }
]

// ---------------- 寄售 / 转赠 ----------------
export const resaleListings = [
  { id: 1, listingNo: 'RS202609040001', userId: 1001, sellerName: '罗盘先生', userPhone: '13812340001', collectibleId: 9001, collectibleName: '龙纹罗盘', cover: '/images/collections/cover-1.jpg', serial: 'SN-9001-0032', price: 599, status: 'onsale', createTime: dt(ts(1, 2)) },
  { id: 2, listingNo: 'RS202609030002', userId: 1004, sellerName: '山海经考据党', userPhone: '13812340004', collectibleId: 9006, collectibleName: '山海经·异兽图', cover: '/images/collections/cover-5.jpg', serial: 'SN-9006-0157', price: 219, status: 'onsale', createTime: dt(ts(2, 5)) },
  { id: 3, listingNo: 'RS202609020003', userId: 1006, sellerName: '织锦护膊', userPhone: '13812340006', collectibleId: 9007, collectibleName: '千里江山·数字长卷', cover: '/images/collections/cover-collection-1.jpg', serial: 'SN-9007-0901', price: 699, status: 'frozen', createTime: dt(ts(3, 1)) },
  { id: 4, listingNo: 'RS202609010004', userId: 1008, sellerName: '虎符将军', userPhone: '13812340008', collectibleId: 9009, collectibleName: '战国错金铭文虎符', cover: '/images/collections/cover-collection-3.jpg', serial: 'SN-9009-0233', price: 1288, status: 'sold', createTime: dt(ts(4, 6)) },
  { id: 5, listingNo: 'RS202608310005', userId: 1002, sellerName: '青铜爱好者', userPhone: '13812340002', collectibleId: 9003, collectibleName: '青铜面具', cover: '/images/collections/cover-3.jpg', serial: 'SN-9003-0110', price: 259, status: 'onsale', createTime: dt(ts(5, 3)) },
  { id: 6, listingNo: 'RS202608300006', userId: 1010, sellerName: '星轨观测员', userPhone: '13812340010', collectibleId: 9002, collectibleName: '云端法器', cover: '/images/collections/cover-2.jpg', serial: 'SN-9002-0455', price: 369, status: 'cancelled', createTime: dt(ts(6, 8)) },
  { id: 7, listingNo: 'RS202608290007', userId: 1005, sellerName: '长卷收卷人', userPhone: '13812340005', collectibleId: 9007, collectibleName: '千里江山·数字长卷', cover: '/images/collections/cover-collection-1.jpg', serial: 'SN-9007-1244', price: 588, status: 'onsale', createTime: dt(ts(7, 4)) }
]

export const transfers = [
  { id: 1, fromUserId: 1001, fromUser: '罗盘先生', toUser: '织锦护膊', toPhone: '13812340006', collectibleName: '青铜面具', cover: '/images/collections/cover-3.jpg', serial: 'SN-9003-0087', status: 'pending', createTime: dt(ts(0, 3)) },
  { id: 2, fromUserId: 1004, fromUser: '山海经考据党', toUser: '虎符将军', toPhone: '13812340008', collectibleName: '山海经·异兽图', cover: '/images/collections/cover-5.jpg', serial: 'SN-9006-0321', status: 'pending', createTime: dt(ts(0, 7)) },
  { id: 3, fromUserId: 1006, fromUser: '织锦护膊', toUser: '司南藏友8823', toPhone: '13812340003', collectibleName: '云端法器', cover: '/images/collections/cover-2.jpg', serial: 'SN-9002-0623', status: 'completed', createTime: dt(ts(1, 4)) },
  { id: 4, fromUserId: 1008, fromUser: '虎符将军', toUser: '青铜爱好者', toPhone: '13812340002', collectibleName: '龙纹罗盘', cover: '/images/collections/cover-1.jpg', serial: 'SN-9001-0155', status: 'completed', createTime: dt(ts(2, 6)) },
  { id: 5, fromUserId: 1010, fromUser: '星轨观测员', toUser: '金错刀', toPhone: '13812340011', collectibleName: '洛神赋·绢本残卷', cover: '/images/collections/cover-collection-2.jpg', serial: 'SN-9008-0066', status: 'rejected', createTime: dt(ts(3, 3)) },
  { id: 6, fromUserId: 1002, fromUser: '青铜爱好者', toUser: '面具收藏家', toPhone: '13812340012', collectibleName: '山海经·异兽图', cover: '/images/collections/cover-5.jpg', serial: 'SN-9006-0209', status: 'completed', createTime: dt(ts(4, 1)) }
]

// ---------------- 营销活动 ----------------
export const checkinConfig = {
  enabled: 1,
  todayCount: 486,
  monthCount: 11820,
  streakTop: [
    { nickname: '织锦护膊', streak: 128 },
    { nickname: '罗盘先生', streak: 96 },
    { nickname: '虎符将军', streak: 77 }
  ],
  rules: [
    { day: 1, type: 'points', label: '5 司南币' },
    { day: 2, type: 'points', label: '8 司南币' },
    { day: 3, type: 'points', label: '10 司南币' },
    { day: 7, type: 'points', label: '20 司南币' },
    { day: 15, type: 'points', label: '50 司南币' },
    { day: 30, type: 'collectible', label: '青铜面具 ×1', collectibleId: 9003 }
  ]
}

export const luckyDraws = [
  {
    id: 1, name: '司南·中秋寻宝转盘', status: 'enabled', startTime: dt(ts(3)), endTime: dt(tsA(7)),
    chancesIssued: 2130, chancesUsed: 1846, drawnCount: 1846,
    prizes: [
      { id: 1, tier: '一等奖·龙纹罗盘', type: 'collectible', name: '龙纹罗盘', cover: '/images/collections/cover-1.jpg', total: 5, won: 4, probability: 0.02 },
      { id: 2, tier: '二等奖·云端法器', type: 'collectible', name: '云端法器', cover: '/images/collections/cover-2.jpg', total: 3, won: 2, probability: 0.02 },
      { id: 3, tier: '三等奖·青铜面具', type: 'collectible', name: '青铜面具', cover: '/images/collections/cover-3.jpg', total: 8, won: 6, probability: 0.03 },
      { id: 4, tier: '100 司南币', type: 'points', amount: 100, total: 20, won: 17, probability: 0.08 },
      { id: 5, tier: '5 司南币', type: 'points', amount: 5, total: 100, won: 91, probability: 0.45 },
      { id: 6, tier: '谢谢参与', type: 'none', total: 500, won: 1726, probability: 0.4 }
    ]
  },
  {
    id: 2, name: '司南·五一限时抽奖', status: 'disabled', startTime: dt(ts(120)), endTime: dt(ts(95)),
    chancesIssued: 6400, chancesUsed: 6210, drawnCount: 6210,
    prizes: [
      { id: 11, tier: '一等奖·洛神赋残卷', type: 'collectible', name: '洛神赋·绢本残卷', cover: '/images/collections/cover-collection-2.jpg', total: 3, won: 3, probability: 0.01 },
      { id: 12, tier: '50 司南币', type: 'points', amount: 50, total: 200, won: 198, probability: 0.19 },
      { id: 13, tier: '谢谢参与', type: 'none', total: 2000, won: 6009, probability: 0.8 }
    ]
  }
]

export const synthesisActivities = [
  {
    id: 1, title: '青铜面具合成计划', type: 'permanent', status: 'enabled', startTime: dt(ts(20)),
    rules: '集齐龙纹罗盘与云端法器各一份，即可合成青铜面具限定藏品。',
    materials: [
      { collectibleId: 9001, name: '龙纹罗盘', cover: '/images/collections/cover-1.jpg', count: 1 },
      { collectibleId: 9002, name: '云端法器', cover: '/images/collections/cover-2.jpg', count: 1 }
    ],
    result: { collectibleId: 9003, name: '青铜面具', cover: '/images/collections/cover-3.jpg' },
    perUserLimit: 1, totalLimit: null, usedCount: 128
  },
  {
    id: 2, title: '千里江山·长卷合成', type: 'limited', status: 'enabled', startTime: dt(ts(5)), endTime: dt(tsA(9)),
    rules: '两幅异兽图合成一卷千里江山数字长卷，限量 500 份。',
    materials: [
      { collectibleId: 9006, name: '山海经·异兽图', cover: '/images/collections/cover-5.jpg', count: 2 }
    ],
    result: { collectibleId: 9007, name: '千里江山·数字长卷', cover: '/images/collections/cover-collection-1.jpg' },
    perUserLimit: 2, totalLimit: 500, usedCount: 213
  }
]

export const inviteActivity = {
  name: '邀友注册·双方得限定藏品', status: 'enabled', mode: 'realtime',
  startTime: dt(ts(60)),
  inviterReward: { type: 'collectible', name: '青铜面具', cover: '/images/collections/cover-3.jpg', quantity: 1 },
  inviteeReward: { type: 'collectible', name: '青铜面具', cover: '/images/collections/cover-3.jpg', quantity: 1 },
  stats: { invitedCount: 328, registerCount: 328, rewardIssued: 641 },
  records: [
    { id: 1, inviter: '织锦护膊', invitee: '面具收藏家', reward: '青铜面具 ×1', status: 'issued', time: dt(ts(0, 5)) },
    { id: 2, inviter: '罗盘先生', invitee: '星轨观测员', reward: '青铜面具 ×1', status: 'issued', time: dt(ts(2, 3)) },
    { id: 3, inviter: '虎符将军', invitee: '异兽图鉴', reward: '青铜面具 ×1', status: 'issued', time: dt(ts(5, 6)) },
    { id: 4, inviter: '山海经考据党', invitee: '金错刀', reward: '青铜面具 ×1', status: 'issued', time: dt(ts(8, 2)) }
  ]
}

export const prioritySales = [
  {
    id: 1, name: '龙纹罗盘·优先购', type: 'priority', collectibleId: 9001, collectibleName: '龙纹罗盘',
    cover: '/images/collections/cover-1.jpg', startTime: dt(ts(0, 10)), endTime: dt(tsA(3)), status: 'enabled', whitelistCount: 120,
    whitelists: [
      { id: 1, userId: 1001, nickname: '罗盘先生', phone: '13812340001', maxQuantity: 2, usedQuantity: 1, expiresAt: dt(tsA(3)) },
      { id: 2, userId: 1002, nickname: '青铜爱好者', phone: '13812340002', maxQuantity: 1, usedQuantity: 0, expiresAt: dt(tsA(3)) },
      { id: 3, userId: 1006, nickname: '织锦护膊', phone: '13812340006', maxQuantity: 3, usedQuantity: 2, expiresAt: dt(tsA(3)) }
    ]
  },
  {
    id: 2, name: '星轨徽章·资格购', type: 'qualification', collectibleId: 9005, collectibleName: '司南·星轨徽章',
    cover: '/images/collections/cover-4.jpg', startTime: dt(tsA(1)), endTime: dt(tsA(6)), status: 'enabled', whitelistCount: 86,
    whitelists: [
      { id: 11, userId: 1010, nickname: '星轨观测员', phone: '13812340010', maxQuantity: 1, usedQuantity: 0, expiresAt: dt(tsA(6)) },
      { id: 12, userId: 1008, nickname: '虎符将军', phone: '13812340008', maxQuantity: 1, usedQuantity: 0, expiresAt: dt(tsA(6)) }
    ]
  }
]

// ---------------- 钱包流水 ----------------
export const walletTransactions = [
  { id: 1, userId: 1001, userName: '罗盘先生', userPhone: '13812340001', type: 'recharge', title: '司南币充值', direction: 1, amount: 1000, balanceAfter: 2860.5, createTime: dt(ts(0, 2)) },
  { id: 2, userId: 1006, userName: '织锦护膊', userPhone: '13812340006', type: 'consume', title: '购买 司南秘宝盲盒 ×5', direction: -1, amount: 495, balanceAfter: 3200, createTime: dt(ts(0, 2)) },
  { id: 3, userId: 1001, userName: '罗盘先生', userPhone: '13812340001', type: 'consume', title: '购买 千里江山·数字长卷', direction: -1, amount: 499, balanceAfter: 1860.5, createTime: dt(ts(0, 1)) },
  { id: 4, userId: 1004, userName: '山海经考据党', userPhone: '13812340004', type: 'reward', title: '抽奖获得 100 司南币', direction: 1, amount: 100, balanceAfter: 1230, createTime: dt(ts(0, 6)) },
  { id: 5, userId: 1006, userName: '织锦护膊', userPhone: '13812340006', type: 'reward', title: '每日签到奖励', direction: 1, amount: 20, balanceAfter: 3695, createTime: dt(ts(0, 8)) },
  { id: 6, userId: 1005, userName: '长卷收卷人', userPhone: '13812340005', type: 'refund', title: '订单退款 SN20260902101519', direction: 1, amount: 499, balanceAfter: 89.9, createTime: dt(ts(2, 9)) },
  { id: 7, userId: 1008, userName: '虎符将军', userPhone: '13812340008', type: 'recharge', title: '司南币充值', direction: 1, amount: 500, balanceAfter: 990, createTime: dt(ts(1, 4)) },
  { id: 8, userId: 1012, userName: '面具收藏家', userPhone: '13812340012', type: 'consume', title: '购买 青铜面具', direction: -1, amount: 199, balanceAfter: 199, createTime: dt(ts(2, 8)) },
  { id: 9, userId: 1007, userName: '洛神赋吟者', userPhone: '13812340007', type: 'reward', title: '邀请好友注册奖励', direction: 1, amount: 50, balanceAfter: 456.6, createTime: dt(ts(3, 2)) },
  { id: 10, userId: 1010, userName: '星轨观测员', userPhone: '13812340010', type: 'consume', title: '购买 云端法器 ×2', direction: -1, amount: 598, balanceAfter: 780, createTime: dt(ts(1, 9)) },
  { id: 11, userId: 1002, userName: '青铜爱好者', userPhone: '13812340002', type: 'recharge', title: '司南币充值', direction: 1, amount: 300, balanceAfter: 540, createTime: dt(ts(4, 3)) },
  { id: 12, userId: 1009, userName: '异兽图鉴', userPhone: '13812340009', type: 'reward', title: '抽奖获得 5 司南币', direction: 1, amount: 5, balanceAfter: 12, createTime: dt(ts(3, 7)) }
]

// ---------------- 内容 ----------------
export const announcements = [
  { id: 1, title: '司南·星轨徽章首发预告', type: 'activity', status: 'published', publishTime: dt(ts(0, 3)), views: 12840 },
  { id: 2, title: '系统升级维护公告（9月10日 02:00-06:00）', type: 'maintenance', status: 'published', publishTime: dt(ts(1, 5)), views: 8320 },
  { id: 3, title: '中秋寻宝转盘活动规则说明', type: 'activity', status: 'published', publishTime: dt(ts(3, 2)), views: 20110 },
  { id: 4, title: '寄售市场交易规范 v2.0 上线', type: 'system', status: 'published', publishTime: dt(ts(6, 4)), views: 5620 },
  { id: 5, title: '十一国庆限定系列预热', type: 'activity', status: 'draft', publishTime: null, views: 0 }
]

export const banners = [
  { id: 1, image: '/images/hero/slide-1.jpg', title: '司南珍藏系列首发', link: '/collection/9001', sort: 1, status: 1 },
  { id: 2, image: '/images/hero/slide-2.jpg', title: '中秋寻宝转盘', link: '/lottery', sort: 2, status: 1 },
  { id: 3, image: '/images/hero/slide-3.jpg', title: '千里江山数字长卷', link: '/collection/9007', sort: 3, status: 0 }
]

export const communityGroups = [
  { id: 1, icon: '/images/tab/tab-bell.png', name: '司南官方社群', description: '第一时间获取发售与活动资讯', qrCode: '/images/brand-logo.png', members: 12800, isActive: 1, sort: 1 },
  { id: 2, icon: '/images/tab/tab-person.png', name: '司南玩家交流群', description: '藏友互动，分享收藏心得', qrCode: null, members: 8600, isActive: 1, sort: 2 }
]

export const artifacts = [
  { id: 1, name: '司南青铜罗盘', dynasty: '战国', image: '/images/exhibits/exhibit-1.jpg', museum: '河北博物院', level: '国家一级文物', material: '青铜', status: 1 },
  { id: 2, name: '云雷纹青铜鼎', dynasty: '西周', image: '/images/exhibits/exhibit-2.jpg', museum: '宝鸡青铜器博物院', level: '国家一级文物', material: '青铜', status: 1 },
  { id: 3, name: '鎏金铜面具', dynasty: '东汉', image: '/images/exhibits/exhibit-3.jpg', museum: '三星堆博物馆', level: '国家一级文物', material: '铜鎏金', status: 1 },
  { id: 4, name: '错金银铜版兆域图', dynasty: '战国', image: '/images/exhibits/exhibit-4.jpg', museum: '河北博物院', level: '国家一级文物', material: '青铜', status: 0 }
]

// ---------------- 日志 / 配置 ----------------
export const loginLogs = [
  { id: 1, username: 'admin', name: '超级管理员', ip: '192.168.1.100', location: '北京市', result: 'success', time: dt(ts(0, 1)) },
  { id: 2, username: 'wangyun', name: '王运营', ip: '192.168.1.108', location: '杭州市', result: 'success', time: dt(ts(0, 5)) },
  { id: 3, username: 'admin', name: '超级管理员', ip: '10.2.34.88', location: '北京市', result: 'fail', time: dt(ts(1, 2)) },
  { id: 4, username: 'admin', name: '超级管理员', ip: '192.168.1.100', location: '北京市', result: 'success', time: dt(ts(1, 9)) },
  { id: 5, username: 'lisheg', name: '李审核', ip: '192.168.2.55', location: '深圳市', result: 'success', time: dt(ts(12)) }
]

export const operationLogs = [
  { id: 1, admin: 'admin', module: '藏品管理', action: '强制售罄', detail: '洛神赋·绢本残卷（#9008）', ip: '192.168.1.100', time: dt(ts(0, 3)) },
  { id: 2, admin: 'wangyun', module: '营销活动', action: '新建抽奖活动', detail: '司南·中秋寻宝转盘', ip: '192.168.1.108', time: dt(ts(0, 6)) },
  { id: 3, admin: 'admin', module: '藏品管理', action: '独立空投', detail: '青铜面具 ×2 → 138****0012', ip: '192.168.1.100', time: dt(ts(1, 1)) },
  { id: 4, admin: 'wangyun', module: '盲盒管理', action: '修改奖池概率', detail: '司南秘宝盲盒 · 云端法器 0.25→0.20', ip: '192.168.1.108', time: dt(ts(1, 4)) },
  { id: 5, admin: 'admin', module: '订单管理', action: '退款审批', detail: '同意 SN20260830162257 退款 ¥399', ip: '192.168.1.100', time: dt(ts(2, 9)) },
  { id: 6, admin: 'admin', module: '用户管理', action: '冻结账号', detail: '长卷收卷人（#1005）', ip: '192.168.1.100', time: dt(ts(3, 3)) },
  { id: 7, admin: 'wangyun', module: '内容管理', action: '发布公告', detail: '中秋寻宝转盘活动规则说明', ip: '192.168.1.108', time: dt(ts(3, 2)) },
  { id: 8, admin: 'admin', module: '系统管理', action: '修改站点配置', detail: '客服二维码更新', ip: '192.168.1.100', time: dt(ts(5, 5)) }
]

export const siteConfig = {
  siteName: '司南珍藏',
  logo: '/images/platform-logo.png',
  announcement: '司南·星轨徽章将于 09-07 18:00 开启首发，敬请期待！',
  maintenance: 0,
  icp: '京ICP备2026000000号-1',
  kefuQr: '/images/brand-logo.png'
}

// ---------------- 看板 ----------------
export const dashboard = {
  todayGmv: 38426.5,
  yesterdayGmv: 35210,
  todayOrders: 158,
  yesterdayOrders: 143,
  todayNewUsers: 63,
  yesterdayNewUsers: 51,
  totalUsers: 26518,
  totalGmv: 8942160,
  pendingRealname: 3,
  pendingRefunds: 2,
  abnormalOrders: 1,
  pendingTransfers: 2,
  trend: [
    { date: '08-30', gmv: 28900, orders: 121, users: 41 },
    { date: '08-31', gmv: 31200, orders: 135, users: 46 },
    { date: '09-01', gmv: 27600, orders: 118, users: 39 },
    { date: '09-02', gmv: 33400, orders: 142, users: 52 },
    { date: '09-03', gmv: 36800, orders: 150, users: 57 },
    { date: '09-04', gmv: 35210, orders: 143, users: 51 },
    { date: '09-05', gmv: 38426, orders: 158, users: 63 }
  ],
  categoryShare: [
    { label: '青铜', value: 32 },
    { label: '水墨', value: 28 },
    { label: '国潮', value: 22 },
    { label: '限定', value: 18 }
  ],
  topCollectibles: [
    { name: '司南秘宝盲盒', sold: 4812, amount: 476388, cover: '/images/collections/cover-collection-bb1.jpg' },
    { name: '千里江山·数字长卷', sold: 2871, amount: 1432386, cover: '/images/collections/cover-collection-1.jpg' },
    { name: '龙纹罗盘', sold: 932, amount: 371868, cover: '/images/collections/cover-1.jpg' },
    { name: '山海经·异兽图', sold: 933, amount: 157677, cover: '/images/collections/cover-5.jpg' },
    { name: '青铜面具', sold: 510, amount: 101490, cover: '/images/collections/cover-3.jpg' }
  ]
}

// ============================================================
// v2.0 扩展数据（BuildAdmin 提示词补漏模块）
// ============================================================

// ---- 藏品/盲盒新字段统一注入（寄售/转赠开关、价格管控、发售配置、资格购）----
collectibles.forEach((c) => {
  c.isTransferable ??= 1        // 转赠开关（独立于寄售）
  c.isResaleable ??= 1          // 寄售开关（独立于转赠）
  c.resalePriceMode ??= 'free'   // 价格管控模式：limit 限价 / free 不限价
  c.resalePriceMin ??= null     // 限价下限（元，精确到分）
  c.resalePriceMax ??= null     // 限价上限
  c.saleQuantity ??= c.edition  // 发售数量（<= 库存池）
  c.perUserLimit ??= 2          // 每人限购
  c.qualificationEnabled ??= 0  // 资格购开关
})
// 示例：龙纹罗盘开启限价管控；洛神赋已关闭寄售
Object.assign(collectibles.find((c) => c.id === 9001), { isResaleable: 1, resalePriceMode: 'limit', resalePriceMin: 350, resalePriceMax: 699 })
Object.assign(collectibles.find((c) => c.id === 9008), { isResaleable: 0, isTransferable: 1 })
Object.assign(collectibles.find((c) => c.id === 9007), { qualificationEnabled: 1 })

// ---- 盲盒 v2.0 字段注入（库存恒等式 / 流通量 / 开关）----
// 盲盒库存池 = edition - sold - airdroppedCount - destroyedCount
// 盲盒流通量 = sold + airdroppedCount
blindBoxes.forEach((b) => {
  b.airdroppedCount ??= 0
  b.destroyedCount ??= 0
  b.circulate ??= b.sold
  b.isTransferable ??= 1
  b.isResaleable ??= 1
  b.saleQuantity ??= b.edition
  b.perUserLimit ??= 5
})
Object.assign(blindBoxes.find((b) => b.id === 1), { airdroppedCount: 120, destroyedCount: 30, circulate: 4812 + 120 })

// ---- 用户黑名单字段注入 ----
users.forEach((u) => {
  u.isBlacklisted ??= 0
  u.blacklistReason ??= ''
})
Object.assign(users.find((u) => u.id === 1005), { isBlacklisted: 1, blacklistReason: '多次恶意抢购后批量退款', blacklistAt: dt(ts(5)) })

// ---- 资格购配置（购买门槛系统，独立于优先购）----
export const qualifications = [
  {
    id: 1, collectibleId: 9007, collectibleName: '千里江山·数字长卷', cover: '/images/collections/cover-collection-1.jpg',
    isEnabled: 1, conditionType: 1, validStartAt: dt(ts(2)), validEndAt: dt(tsA(12)),
    requiredCollectibles: [
      { collectibleId: 9001, name: '龙纹罗盘', cover: '/images/collections/cover-1.jpg' },
      { collectibleId: 9002, name: '云端法器', cover: '/images/collections/cover-2.jpg' }
    ],
    requiredCheckinDays: 7, requiredInviteCount: 3,
    whitelist: [
      { id: 1, userId: 1006, nickname: '织锦护膊', phone: '13812340006', expiresAt: dt(tsA(12)) },
      { id: 2, userId: 1010, nickname: '星轨观测员', phone: '13812340010', expiresAt: dt(tsA(12)) }
    ],
    qualifiedCount: 386
  },
  {
    id: 2, collectibleId: 9005, collectibleName: '司南·星轨徽章', cover: '/images/collections/cover-4.jpg',
    isEnabled: 1, conditionType: 2, validStartAt: dt(tsA(1)), validEndAt: dt(tsA(6)),
    requiredCollectibles: [],
    requiredCheckinDays: 30, requiredInviteCount: 0,
    whitelist: [
      { id: 11, userId: 1008, nickname: '虎符将军', phone: '13812340008', expiresAt: dt(tsA(6)) }
    ],
    qualifiedCount: 86
  }
]

// ---- 销毁记录台账 ----
export const destroyRecords = [
  { id: 1, targetName: '司南秘宝盲盒', targetType: 'blindbox', quantity: 8, operator: 'admin', time: dt(ts(9, 2)), remark: '瑕疵品销毁' },
  { id: 2, targetName: '龙纹罗盘', targetType: 'collectible', quantity: 2, operator: 'admin', time: dt(ts(20, 1)), remark: '合约迁移销毁' }
]

// ---- 求购市场 ----
export const buyRequests = [
  { id: 1, userName: '虎符将军', collectibleName: '战国错金铭文虎符', price: 1500, quantity: 1, status: 'active', createTime: dt(ts(0, 3)) },
  { id: 2, userName: '青铜爱好者', collectibleName: '龙纹罗盘', price: 500, quantity: 2, status: 'active', createTime: dt(ts(1, 5)) },
  { id: 3, userName: '洛神赋吟者', collectibleName: '洛神赋·绢本残卷', price: 400, quantity: 1, status: 'delisted', createTime: dt(ts(3, 6)) }
]

// ---- 风控告警 ----
export const riskAlerts = [
  { id: 1, type: 'abnormal_trade', level: 'high', userName: '长卷收卷人', userPhone: '13812340005', detail: '10 分钟内连续寄售挂单 6 次，触发异常交易预警', status: 'pending', createTime: dt(ts(0, 2)) },
  { id: 2, type: 'bulk_refund', level: 'high', userName: '面具收藏家', userPhone: '13812340012', detail: '24 小时内申请退款 3 笔，疑似恶意退款', status: 'pending', createTime: dt(ts(0, 7)) },
  { id: 3, type: 'bulk_register', level: 'medium', userName: '星轨观测员', userPhone: '13812340010', detail: '邀请注册的新设备指纹重复率达 80%，疑似批量注册', status: 'processing', createTime: dt(ts(1, 3)) },
  { id: 4, type: 'abnormal_trade', level: 'low', userName: '金错刀', userPhone: '13812340011', detail: '转赠对象高度集中于同一接收人（5 次）', status: 'resolved', createTime: dt(ts(2, 9)), handleTime: dt(ts(2, 5)), handler: '李风控', result: '核实为家人间流转，已放行' },
  { id: 5, type: 'price_manipulation', level: 'medium', userName: '织锦护膊', userPhone: '13812340006', detail: '寄售价格偏离限价区间上限 15%', status: 'resolved', createTime: dt(ts(3, 4)), handleTime: dt(ts(3, 2)), handler: '李风控', result: '已强制下架并通知用户' }
]

// ---- 客服工单 ----
export const tickets = [
  {
    id: 1, ticketNo: 'TK202609050001', userName: '司南藏友8823', userPhone: '13812340003', type: 'order', priority: 'high',
    title: '支付成功但未到账', content: '购买山海经·异兽图支付后仓库一直没到货，请处理。', status: 'pending', createTime: dt(ts(0, 4)), replies: []
  },
  {
    id: 2, ticketNo: 'TK202609040002', userName: '面具收藏家', userPhone: '13812340012', type: 'refund', priority: 'urgent',
    title: '退款进度查询', content: '昨天申请的退款还没到账，麻烦帮忙看看。', status: 'processing', createTime: dt(ts(1, 6)),
    replies: [{ id: 1, author: '苏客服', content: '您好，已加急为您核实，预计 2 小时内到账。', time: dt(ts(1, 4)) }]
  },
  {
    id: 3, ticketNo: 'TK202609030003', userName: '青铜爱好者', userPhone: '13812340002', type: 'account', priority: 'normal',
    title: '修改绑定手机号', content: '手机号换绑为 139****2233。', status: 'closed', createTime: dt(ts(2, 8)), closeTime: dt(ts(2, 5)),
    replies: [
      { id: 2, author: '苏客服', content: '已完成换绑，请使用新手机号登录。', time: dt(ts(2, 6)) },
      { id: 3, author: '青铜爱好者', content: '好的，谢谢。', time: dt(ts(2, 5)) }
    ]
  }
]

// ---- 区块链：智能合约 ----
export const chainContracts = [
  { id: 1, chainType: 'ETH', chainEnv: 'mainnet', contractName: 'SinanNFTCore', contractAddress: '0x7d5...a3f8', txCount: 128406, status: 1, createTime: dt(ts(180)) },
  { id: 2, chainType: 'ETH', chainEnv: 'testnet', contractName: 'SinanBlindBox', contractAddress: '0x9c1...e2b7', txCount: 8212, status: 1, createTime: dt(ts(90)) },
  { id: 3, chainType: 'POLYGON', chainEnv: 'mainnet', contractName: 'SinanMarket', contractAddress: '0x3fa...11cd', txCount: 21044, status: 0, createTime: dt(ts(60)) }
]

// ---- 区块链：链上交易 ----
export const chainTransactions = [
  { id: 1, txHash: '0x8f2e...c4d9', type: 'Mint', contractName: 'SinanNFTCore', userName: '罗盘先生', token: '龙纹罗盘 #0332', gas: '0.0021 ETH', status: 'success', blockTime: dt(ts(0, 2)) },
  { id: 2, txHash: '0x1a7b...9e03', type: 'Transfer', contractName: 'SinanNFTCore', userName: '织锦护膊', token: '青铜面具 #0087', gas: '0.0018 ETH', status: 'success', blockTime: dt(ts(0, 5)) },
  { id: 3, txHash: '0xd4c8...77aa', type: 'Sale', contractName: 'SinanMarket', userName: '虎符将军', token: '战国错金铭文虎符 #0233', gas: '0.0035 ETH', status: 'pending', blockTime: dt(ts(0, 8)) },
  { id: 4, txHash: '0x55e0...bb12', type: 'Mint', contractName: 'SinanBlindBox', userName: '面具收藏家', token: '司南秘宝盲盒 #4812', gas: '0.0026 ETH', status: 'failed', blockTime: dt(ts(1, 2)) }
]

// ---- 内容审核（用户发布内容）----
export const contentAudits = [
  { id: 1, type: 'ugc_collectible', userName: '洛神赋吟者', userPhone: '13812340007', title: '自制异兽图二创', content: '基于山海经·异兽图创作的二创作品，申请上架。', cover: '/images/collections/cover-5.jpg', copyright: '原创声明截图', status: 'pending', submitTime: dt(ts(0, 3)) },
  { id: 2, type: 'community_post', userName: '青铜爱好者', userPhone: '13812340002', title: '社群分享帖', content: '分享龙纹罗盘开箱体验（内含外站二维码）', cover: null, copyright: null, status: 'rejected', submitTime: dt(ts(1, 6)), handleTime: dt(ts(1, 3)), reason: '含外站导流二维码，违反社区规范' },
  { id: 3, type: 'ugc_collectible', userName: '金错刀', userPhone: '13812340011', title: '鎏金面具临摹', content: '三星堆鎏金面具数字临摹作品。', cover: '/images/collections/cover-3.jpg', copyright: '原创声明截图', status: 'approved', submitTime: dt(ts(2, 4)), handleTime: dt(ts(2, 1)) }
]

// ---- 敏感操作审批工作流 ----
export const approvals = [
  { id: 1, type: 'large_refund', title: '大额退款审批：SN20260904200916 ¥899', applicant: '苏客服', amount: 899, status: 'pending', createTime: dt(ts(0, 5)), reason: '用户误购，客服核实后发起' },
  { id: 2, type: 'asset_modify', title: '强制修改用户资产：织锦护膊 +2 龙纹罗盘', applicant: '王运营', amount: null, status: 'pending', createTime: dt(ts(1, 2)), reason: '活动补发（空投失败补偿）' },
  { id: 3, type: 'config_modify', title: '修改支付配置：支付宝商户号变更', applicant: '钱财务', amount: null, status: 'approved', createTime: dt(ts(2, 6)), handleTime: dt(ts(2, 3)), handler: 'admin' },
  { id: 4, type: 'large_refund', title: '大额退款审批：SN20260902101519 ¥499', applicant: '苏客服', amount: 499, status: 'rejected', createTime: dt(ts(3, 1)), handleTime: dt(ts(3, 4)), handler: 'admin', reason: '用户藏品已发生二次流转' }
]

// ---- 站点配置扩展（支付/短信/区块链/存储/手续费）----
Object.assign(siteConfig, {
  feeMode: 'rate',            // 手续费模式：rate 比例 / fixed 固定
  feeRate: 0.05,              // 比例 5%
  feeFixed: 5,                // 固定 5 元
  alipayEnabled: 1, wechatEnabled: 1, unionpayEnabled: 0, cryptoEnabled: 0,
  chainRpc: 'https://mainnet.infura.io/v3/***',
  chainId: 1,
  gasStrategy: 'medium',       // low / medium / high（平台补贴）
  storageType: 'oss'           // oss / ipfs
})

// ---- 统计报表 ----
export const statistics = {
  dau: 3218,
  dauTrend: [2100, 2350, 2280, 2600, 2850, 2980, 3218],
  retention: [
    { label: '次日留存', value: 42 },
    { label: '7日留存', value: 28 },
    { label: '30日留存', value: 19 }
  ],
  finance: {
    monthIncome: 286400, monthFee: 14320, monthRecharge: 526000, monthWithdraw: 88000,
    incomeTrend: [18200, 21400, 19800, 23600, 25100, 27300, 28640].map((v, i) => ({ date: `08-${30 + i}`, value: v })),
    feeShare: [
      { label: '寄售手续费', value: 62 },
      { label: '盲盒销售分成', value: 24 },
      { label: '合成材料费', value: 14 }
    ]
  },
  salesRank: dashboard.topCollectibles,
  userTrend: dashboard.trend.map((t) => ({ date: t.date, value: t.users }))
}

// ---- 管理员密码验证（敏感操作二次校验，Mock 仅 admin123）----
export const verifyPassword = (pwd) => pwd === 'admin123'

// 供视图直接使用的工具
export const helpers = { dt, dOnly, ts, tsA }
