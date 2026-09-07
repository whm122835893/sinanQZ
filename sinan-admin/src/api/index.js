// ============================================================
// 司南珍藏 · 管理后台 API 层（真实联调版）
// 设计原则：
//   1. 对视图层：函数签名与返回结构沿用 mock 时代的约定（res.code===0 / res.data）
//      → 40+ 个视图文件无需感知后端变化
//   2. 对后端：调用 ThinkPHP admin 应用真实端点（/api/admin/** 代理）
//   3. 适配层：后端 snake_case/数值状态 → 前端 camelCase/语义状态
//      （状态映射、字段改名、嵌套拍平集中在此，视图零侵入）
// ============================================================
import { get, post, put, del, http } from '@/utils/request'

// ------------------------------------------------------------
// 通用映射表
// ------------------------------------------------------------
const REALNAME_STATUS = { 0: 'none', 1: 'pending', 2: 'approved', 3: 'rejected' }
const REFUND_STATUS = { 1: 'pending', 2: 'approved', 3: 'approved', 4: 'rejected' } // 3=已退款归为 approved 终态
const RESALE_STATUS = { selling: 'onsale', sold: 'sold', cancelled: 'cancelled' }
const TRANSFER_STATUS = { pending: 'pending', accepted: 'completed', rejected: 'rejected', cancelled: 'revoked' }
const ORDER_STATUS_MAP = { pending: 'pending', completed: 'completed', cancelled: 'cancelled', refunding: 'refunding', refunded: 'refunded' }
// 钱包流水 trans_type → 前端语义（consume 对应 buy）
const TX_TYPE_MAP = { recharge: 'recharge', reward: 'reward', buy: 'consume', refund: 'refund', withdraw: 'withdraw', sale: 'refund' }

// 类目名 → ID（后端按 category_id 存储；种子数据约定 1水墨 2国潮 3限定）
const CATEGORY_NAME_TO_ID = { 水墨: 1, 国潮: 2, 限定: 3, 青铜: 2 }
const CATEGORY_ID_TO_NAME = { 1: '水墨', 2: '国潮', 3: '限定' }

const n = (v) => (v === null || v === undefined ? 0 : Number(v))
const s = (v) => (v === null || v === undefined ? '' : String(v))

// ============================================================
// 认证
// ============================================================

export function login({ username, password }) {
  // silent：登录页自行处理错误提示，避免双重弹窗
  return post('/auth/login', { username, password }, { silent: true })
}

export async function logout() {
  // 后端注销令牌（失败不阻断本地清理）
  await post('/auth/logout', {}, { silent: true })
  localStorage.removeItem('sinan_admin_token')
  localStorage.removeItem('sinan_admin_info')
  return { code: 0, message: 'ok', data: null }
}

/** 敏感操作二次密码校验（平台清库/查看完整实名/大额审批前置） */
export const verifyAdminPassword = (pwd) =>
  post('/auth/verify-password', { password: pwd }, { silent: true })

/** 修改登录密码（个人中心安全设置） */
export const changeAdminPassword = (oldPwd, newPwd, confirmPwd) =>
  post('/auth/change-password', { old_password: oldPwd, new_password: newPwd, confirm_password: confirmPwd })

// ============================================================
// 数据看板
// ============================================================

/**
 * 看板聚合：overview（KPI+待办）+ trend（近7日）+ rank（藏品榜）
 * 聚合成视图约定的扁平结构
 */
export async function getDashboard() {
  const [ov, tr, rk] = await Promise.all([
    getSilentSafe('/dashboard/overview'),
    getSilentSafe('/dashboard/trend', { days: 7 }),
    getSilentSafe('/dashboard/rank', { type: 'collectible', limit: 5 })
  ])
  if (ov.code !== 0) return ov

  const d = ov.data || {}
  const series = (tr.data && tr.data.series) || []
  const last = series[series.length - 1] || {}
  const prev = series[series.length - 2] || {}
  const rankList = (rk.data && rk.data.list) || []

  return {
    code: 0,
    message: 'ok',
    data: {
      todayGmv: n(d.order?.gmvToday),
      yesterdayGmv: n(prev.gmv),
      todayOrders: n(d.order?.today),
      yesterdayOrders: n(prev.orders),
      todayNewUsers: n(d.user?.today),
      yesterdayNewUsers: n(prev.newUser),
      totalUsers: n(d.user?.total),
      totalGmv: n(d.order?.gmvTotal),
      pendingRealname: n(d.todo?.realnamePending),
      pendingRefunds: n(d.todo?.refundPending),
      abnormalOrders: n(d.todo?.abnormalOrders),
      pendingTransfers: n(d.market?.transferPending),
      trend: series.map((t) => ({ date: (t.date || '').slice(5), gmv: n(t.gmv), orders: n(t.orders), users: n(t.newUser) })),
      categoryShare: (d.categoryShare || []).map((c) => ({ label: c.label || '未分类', value: n(c.value) })),
      topCollectibles: rankList.map((r) => ({
        name: r.name,
        sold: n(r.sold),
        amount: n(r.sold) * n(r.price),
        cover: r.image
      }))
    }
  }
}

// 静默请求（失败不弹全局错误，由聚合函数决定展示）
async function getSilentSafe(url, params) {
  const { getSilent } = await import('@/utils/request')
  return getSilent(url, params)
}

// ============================================================
// 用户管理
// ============================================================

const adaptUser = (u) => ({
  id: u.id,
  uid: u.uid,
  nickname: s(u.username),
  phone: s(u.phone),
  avatar: u.avatar,
  registerTime: s(u.createdAt),
  balance: n(u.balance),
  points: n(u.points),
  collectibleCount: n(u.collectibleCount),
  orderCount: n(u.orderCount),
  status: u.status === 'normal' || n(u.status) === 1 ? 'normal' : 'frozen',
  realnameStatus: typeof u.realnameStatus === 'string' ? u.realnameStatus : (REALNAME_STATUS[n(u.realnameStatus)] || 'none'),
  realnameName: s(u.realName),
  realnameIdNo: s(u.idCard),
  isBlacklisted: n(u.isBlacklisted) === 1,
  blacklistReason: s(u.blacklistReason),
  lastLoginTime: s(u.lastLoginAt),
  loginCount: n(u.loginCount)
})

export async function getUserList(params) {
  const res = await get('/users', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return { code: 0, message: res.message, data: { list: (d.list || []).map(adaptUser), total: n(d.total) } }
}

export async function getUserDetail(id) {
  const res = await get(`/users/${id}`)
  if (res.code !== 0) return res
  const u = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      ...adaptUser(u),
      available: n(u.wallet?.available),
      frozen: n(u.wallet?.frozen),
      orders: (u.recentOrders || []).map((o) => ({
        id: o.id,
        orderNo: o.orderNo,
        collectibleName: o.collectibleName,
        amount: n(o.totalPrice),
        status: ORDER_STATUS_MAP[o.status] || o.status,
        createTime: s(o.createdAt)
      })),
      transfers: (u.recentTransfers || []).map((t) => ({
        id: t.id,
        collectibleName: t.collectibleName,
        status: TRANSFER_STATUS[t.status] || t.status,
        isReceive: n(t.isReceive) === 1,
        createTime: s(t.createdAt)
      }))
    }
  }
}

/**
 * 冻结/解冻用户
 * @param {number} id 用户 ID
 * @param {boolean} freeze true=冻结 false=解冻
 * @param {string} reason 冻结原因（冻结必填）
 */
export function freezeUser(id, freeze = true, reason = '') {
  return post(`/users/${id}/freeze`, { status: freeze ? 0 : 1, reason })
}

/** 重置交易密码（用户需在 APP 重新设置） */
export function resetTradePwd(id) {
  return post(`/users/${id}/reset-transaction-password`, {})
}

/** 强制登出（全部登录态失效） */
export function forceLogoutUser(id, reason = '') {
  return post(`/users/${id}/force-logout`, { reason })
}

/**
 * 实名审核（通过/驳回）
 * @param {number} id 用户 ID
 * @param {boolean} pass true=通过
 * @param {string} reason 驳回原因（驳回必填）
 */
export function auditRealname(id, pass, reason = '') {
  return post('/realname/audit', { user_id: id, action: pass ? 'approve' : 'reject', reason })
}

/** 加入/移出黑名单 */
export function toggleBlacklist(id, reason = '') {
  // 加入黑名单必须填原因；移出时后端自动读取原记录
  return post(`/users/${id}/blacklist`, { action: 'add', reason })
}
export function removeBlacklist(id, reason = '') {
  return post(`/users/${id}/blacklist`, { action: 'remove', reason })
}

/**
 * 强制回收藏品（超卖/错空投/多合等资产异常处置）
 * 回收后按资产来源自动回退计数器（sold/airdropped_count/配额）与流通量
 */
export function recoverUserCollectible({ id, reason }) {
  return post('/users/recover', { user_collectible_id: id, reason })
}

/** 用户资产列表（详情抽屉-回收入口数据源；params.status 缺省=有效持仓） */
export async function getUserAssets(id, params = {}) {
  const res = await get(`/users/assets/${id}`, params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((a) => ({
        id: n(a.id),
        serial: s(a.serial),
        status: s(a.status),
        source: s(a.source),
        price: n(a.acquiredPrice),
        acquiredTime: s(a.acquiredAt),
        collectibleId: n(a.collectibleId),
        name: s(a.collectibleName),
        cover: s(a.collectibleImage)
      })),
      total: n(d.total)
    }
  }
}

// ============================================================
// 藏品管理
// ============================================================

const adaptCollectible = (c) => ({
  id: c.id,
  name: s(c.name),
  subtitle: s(c.subtitle),
  cover: s(c.image),
  categoryId: n(c.categoryId),
  category: s(c.categoryName) || CATEGORY_ID_TO_NAME[n(c.categoryId)] || '未分类',
  price: n(c.price),
  edition: n(c.edition),
  circulate: n(c.circulate),
  sold: n(c.sold),
  lockedQuantity: n(c.lockedQuantity),
  reservedCount: n(c.reservedCount),
  airdroppedCount: n(c.airdroppedCount),
  destroyedCount: n(c.destroyedCount),
  status: s(c.status),
  tag: s(c.tag),
  issuer: s(c.issuer),
  saleTime: s(c.onsaleAt),
  description: s(c.description),
  featured: n(c.featured) === 1,
  isBlindBox: n(c.isBlindBox) === 1,
  availablePool: n(c.availablePool),
  isTransferable: n(c.isTransferable) === 1,
  isResaleable: n(c.isResaleable) === 1,
  resalePriceMode: n(c.resalePriceMode),   // 0=不限价 1=固定价 2=区间价
  resalePriceMin: c.resalePriceMin,
  resalePriceMax: c.resalePriceMax,
  perUserLimit: n(c.perUserLimit),
  chainType: s(c.chainType),
  contract: s(c.contract)
})

export async function getCollectibleList(params) {
  const res = await get('/collectibles', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return { code: 0, message: res.message, data: { list: (d.list || []).map(adaptCollectible), total: n(d.total) } }
}

export async function getCollectibleDetail(id) {
  const res = await get(`/collectibles/${id}`)
  if (res.code !== 0) return res
  const d = res.data || {}
  // 后端详情为平铺结构：藏品字段 + quotas/destroyRecords/qualification/holders/inventoryAudit
  const base = adaptCollectible(d)
  const a = d.inventoryAudit || {}
  return {
    code: 0,
    message: res.message,
    data: {
      ...base,
      audit: {
        ok: !!(a.identityOk && a.holdingOk),
        pool: n(a.pool),
        edition: n(a.edition),
        identityDesc: s(a.identityDesc),
        holdingDesc: s(a.holdingDesc)
      },
      holders: (d.holders || []).map((h) => ({
        userId: n(h.userId),
        nickname: s(h.nickname) || `用户${n(h.userId)}`,
        serial: s(h.serial),
        quantity: n(h.quantity)
      })),
      quotas: (d.quotas || []).map((q) => ({
        id: q.id,
        quotaType: n(q.quotaType),
        quotaName: s(q.quotaName),
        plannedQuantity: n(q.plannedQuantity),
        usedQuantity: n(q.usedQuantity),
        status: n(q.status),
        activityType: s(q.activityType),
        remark: s(q.remark)
      })),
      destroyRecords: (d.destroyRecords || []).map((r) => ({
        id: r.id,
        targetName: s(r.targetName),
        targetType: s(r.targetType),
        quantity: n(r.quantity),
        operator: s(r.adminName),
        time: s(r.createdAt),
        remark: s(r.reason)
      })),
      qualification: d.qualification || null
    }
  }
}

/**
 * 新建/编辑藏品
 * 视图载荷：{ id?, name, subtitle, category(名称), price, edition, saleTime, tag,
 *            issuer, creator, royaltyRate, description, featured, cover, ... }
 * 转赠/寄售开关不在此设置：创建后由管理员在藏品列表/详情中配置（market-config）
 */
export function saveCollectible(payload) {
  const body = {
    name: payload.name,
    subtitle: payload.subtitle || '',
    category_id: CATEGORY_NAME_TO_ID[payload.category] || 2,
    image: payload.cover,
    price: n(payload.price),
    edition: n(payload.edition),
    tag: payload.tag || '',
    issuer: payload.issuer || '',
    creator: payload.creator || '',
    description: payload.description || '',
    featured: payload.featured ? 1 : 0,
    release_date: payload.saleTime || '',
    // 链上配置
    ...(payload.chainType ? { chain_type: payload.chainType } : {}),
    ...(payload.contract ? { contract: payload.contract } : {})
  }
  if (payload.id) {
    body.id = payload.id
    return put(`/collectibles/${payload.id}`, body)
  }
  return post('/collectibles', body)
}

/** 图片上传（file: File；biz: collection/blindbox/marketing/content/misc；返回 {url}） */
export function uploadImage(file, biz = 'collection') {
  const fd = new FormData()
  fd.append('file', file)
  fd.append('biz', biz)
  return post('/upload/image', fd, { silent: true })
}

// ============================================================
// 站点装修（C 端全局风格）
// ============================================================

/** 装修配置（键值列表 → 扁平对象） */
export async function getDecoration() {
  const res = await get('/cms/decoration')
  if (res.code !== 0) return res
  const cfg = {}
  for (const item of res.data || []) {
    cfg[item.key] = s(item.value)
  }
  return { code: 0, message: res.message, data: cfg }
}

/** 保存装修配置（批量，仅白名单键） */
export function saveDecoration(settings) {
  return post('/cms/decoration', { settings })
}

/** 站点品牌（公开接口：登录页/侧边栏展示） */
export async function getSiteBrand() {
  const res = await get('/site-brand')
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      siteName: s(d.siteName) || '司南珍藏',
      siteLogo: s(d.siteLogo),
      siteAvatar: s(d.siteAvatar),
      themeColor: s(d.themeColor)
    }
  }
}

/**
 * 上架/下架/强制售罄
 * - 上架（upcoming/offline/soldout → onsale）走发售接口（含库存池校验）
 * - 下架 / 强制售罄走管理接口（后端 manage 仅支持 off / soldout）
 */
export async function toggleCollectibleStatus(id, action) {
  if (action === 'online' || action === 'onsale') {
    const res = await post(`/collectibles/${id}/release`, { status: 'onsale' })
    // release 返回无 data，统一回填最新状态供视图直接使用
    return res.code === 0 ? { ...res, data: 'onsale' } : res
  }
  const act = action === 'forceSoldout' ? 'soldout' : 'off'
  const res = await post(`/collectibles/${id}/manage`, { action: act })
  return res.code === 0 ? { ...res, data: act } : res
}

/** 发售配置（上架时间/发售数量） */
export function releaseCollectible({ id, saleQuantity, price, perUserLimit }) {
  return post(`/collectibles/${id}/release`, {
    status: 'onsale',
    ...(saleQuantity ? { sale_quantity: saleQuantity } : {}),
    ...(price ? { price } : {}),
    ...(perUserLimit ? { per_user_limit: perUserLimit } : {})
  })
}

/** 独立空投 */
export function airdropCollectible({ id, phones, quantity, reason = '运营空投' }) {
  return post('/collectibles/airdrop', { id, users: phones, reason })
}

/** 销毁库存 */
export function destroyCollectible({ id, quantity, reason }) {
  return post(`/collectibles/${id}/destroy`, { quantity, reason })
}

/** 新增配额 */
export function addQuota({ collectibleId, quotaType, quotaName, quantity, activityType = 'other', remark = '' }) {
  return post(`/collectibles/${collectibleId}/quota`, {
    quota_type: quotaType,
    quota_name: quotaName,
    planned_quantity: quantity,
    activity_type: activityType,
    remark
  })
}

/** 配额启停 */
export function toggleQuota(quotaId) {
  return post(`/collectibles/quota/${quotaId}/toggle`)
}

/**
 * 寄售开关 + 价格管控（priceMode：0=不限价 1=固定价 2=区间价；兼容 'free'/'limit' 旧值）
 */
export function toggleCollectibleResale({ id, enabled, priceMode = 0, priceMin = null, priceMax = null }) {
  const mode = priceMode === 'free' ? 0 : priceMode === 'limit' ? 2 : Number(priceMode) || 0
  return put(`/collectibles/${id}/market-config`, {
    is_resaleable: enabled ? 1 : 0,
    resale_price_mode: mode,
    resale_price_min: priceMin === null ? null : Number(priceMin),
    resale_price_max: priceMax === null ? null : Number(priceMax)
  })
}

/** 转赠开关（藏品创建后于列表/详情页配置） */
export function toggleCollectibleTransferable(id, enabled) {
  return put(`/collectibles/${id}/market-config`, { is_transferable: enabled ? 1 : 0 })
}

// ============================================================
// 盲盒管理
// ============================================================

const adaptBlindBox = (b) => ({
  id: b.id,
  collectibleId: n(b.collectibleId),
  name: s(b.name),
  cover: s(b.image),
  price: n(b.price),
  edition: n(b.edition),
  circulate: n(b.circulate ?? b.sold),
  sold: n(b.sold),
  lockedQuantity: n(b.lockedQuantity),
  airdroppedCount: n(b.airdroppedCount),
  destroyedCount: n(b.destroyedCount),
  status: s(b.status),
  isOpenable: n(b.isOpenable) === 1,
  openedCount: n(b.openedCount),
  isTransferable: n(b.isTransferable) === 1,
  isResaleable: n(b.isResaleable) === 1,
  resalePriceMode: n(b.resalePriceMode),
  resalePriceMin: b.resalePriceMin === null || b.resalePriceMin === undefined ? null : n(b.resalePriceMin),
  resalePriceMax: b.resalePriceMax === null || b.resalePriceMax === undefined ? null : n(b.resalePriceMax),
  itemCount: n(b.itemCount),
  probabilitySum: n(b.probabilitySum),
  probabilityOk: !!b.probabilityOk,
  availablePool: n(b.availablePool),
  saleTime: s(b.onsaleAt),
  description: s(b.description)
})

export async function getBlindBoxList(params) {
  const res = await get('/blind-boxes', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return { code: 0, message: res.message, data: { list: (d.list || []).map(adaptBlindBox), total: n(d.total) } }
}

export async function getBlindBoxDetail(id) {
  const res = await get(`/blind-boxes/${id}`)
  if (res.code !== 0) return res
  const d = res.data || {}
  const base = adaptBlindBox(d)
  return {
    code: 0,
    message: res.message,
    data: {
      ...base,
      items: (d.items || []).map((i) => ({
        id: i.id,
        prizeCollectibleId: n(i.prizeCollectibleId),
        prizeName: s(i.prizeName),
        cover: s(i.prizeImage),
        probability: n(i.probability),
        quantityLimit: i.quantityLimit,
        quantityDistributed: n(i.quantityDistributed)
      })),
      audit: d.audit || null,
      destroyRecords: (d.destroyRecords || []).map((r) => ({
        id: r.id,
        targetName: s(r.targetName),
        quantity: n(r.quantity),
        operator: s(r.adminName),
        time: s(r.createdAt),
        remark: s(r.reason)
      }))
    }
  }
}

/** 新建/编辑盲盒（编辑时仅更新基础信息） */
export function saveBlindBox(payload) {
  const body = {
    name: payload.name,
    subtitle: payload.subtitle || '',
    image: payload.cover,
    price: n(payload.price),
    edition: n(payload.edition),
    description: payload.description || '',
    is_openable: payload.isOpenable ? 1 : 0
  }
  if (payload.id) {
    body.id = payload.id
    return put(`/blind-boxes/${payload.id}`, body)
  }
  return post('/blind-boxes', body)
}

/** 盲盒上下架/售罄：action = onsale / off / soldout */
export function toggleBlindBoxStatus(id, action) {
  return post(`/blind-boxes/${id}/manage`, { action })
}

/** 可开启开关 */
export function setBlindBoxOpenable(id, openable) {
  return put(`/blind-boxes/${id}`, { is_openable: openable ? 1 : 0 })
}

/** 盲盒空投 */
export function airdropBlindBox({ id, phones, quantity, reason = '运营空投' }) {
  return post('/blind-boxes/airdrop', { id, users: phones, reason })
}

/** 盲盒销毁 */
export function destroyBlindBox({ id, quantity, reason }) {
  return post(`/blind-boxes/${id}/destroy`, { quantity, reason })
}

/** 盲盒发售 */
export function releaseBlindBox({ id, saleQuantity, price, perUserLimit }) {
  return post(`/blind-boxes/${id}/release`, {
    status: 'onsale',
    ...(saleQuantity ? { sale_quantity: saleQuantity } : {}),
    ...(price ? { price } : {})
  })
}

/** 奖池奖项配置（整体提交 items） */
export function saveBlindBoxPrize({ boxId, prizeCollectibleId, probability, quantityLimit }) {
  // 后端为整体配置：先读详情合并再提交
  return (async () => {
    const detail = await getSilentSafe2(`/blind-boxes/${boxId}`)
    if (detail.code !== 0) return detail
    const items = (detail.data.items || []).map((i) => ({
      id: i.id,
      prize_collectible_id: i.prizeCollectibleId,
      probability: n(i.probability),
      quantity_limit: i.quantityLimit
    }))
    items.push({
      prize_collectible_id: prizeCollectibleId,
      probability: n(probability),
      quantity_limit: quantityLimit
    })
    return put(`/blind-boxes/${boxId}/config`, { items })
  })()
}

/** 移除奖项（重新提交剩余奖池） */
export function removeBlindBoxPrize({ boxId, prizeCollectibleId }) {
  return (async () => {
    const detail = await getSilentSafe2(`/blind-boxes/${boxId}`)
    if (detail.code !== 0) return detail
    const items = (detail.data.items || [])
      .filter((i) => i.prizeCollectibleId !== prizeCollectibleId)
      .map((i) => ({
        id: i.id,
        prize_collectible_id: i.prizeCollectibleId,
        probability: n(i.probability),
        quantity_limit: i.quantityLimit
      }))
    return put(`/blind-boxes/${boxId}/config`, { items })
  })()
}

/** 可选奖池藏品（排除自身） */
export async function getBlindBoxSelectableCollectibles() {
  const res = await get('/collectibles', { page: 1, pageSize: 100, isBlindBox: 0 })
  if (res.code !== 0) return { code: 0, message: 'ok', data: [] }
  return { code: 0, message: 'ok', data: (res.data.list || []).map(adaptCollectible) }
}

async function getSilentSafe2(url) {
  const { getSilent } = await import('@/utils/request')
  return getSilent(url)
}

/** 盲盒寄售/转赠开关（通过藏品市场配置；priceMode：0=不限价 1=固定价 2=区间价） */
export function toggleBlindBoxResale({ id, enabled, priceMode = 0, priceMin = null, priceMax = null }) {
  return (async () => {
    const detail = await getSilentSafe2(`/blind-boxes/${id}`)
    if (detail.code !== 0) return detail
    const cid = detail.data.collectibleId
    const mode = priceMode === 'free' ? 0 : priceMode === 'limit' ? 2 : Number(priceMode) || 0
    return put(`/collectibles/${cid}/market-config`, {
      is_resaleable: enabled ? 1 : 0,
      resale_price_mode: mode,
      resale_price_min: priceMin === null ? null : Number(priceMin),
      resale_price_max: priceMax === null ? null : Number(priceMax)
    })
  })()
}

export function toggleBlindBoxTransferable(id, val) {
  return (async () => {
    const detail = await getSilentSafe2(`/blind-boxes/${id}`)
    if (detail.code !== 0) return detail
    return put(`/collectibles/${detail.data.collectibleId}/market-config`, { is_transferable: val ? 1 : 0 })
  })()
}

// ============================================================
// 订单 / 退款
// ============================================================

export async function getOrderList(params) {
  const res = await get('/orders', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((o) => ({
        id: o.id,
        orderNo: s(o.orderNo),
        userId: n(o.userId),
        userName: s(o.username),
        userPhone: '',
        collectibleId: n(o.collectibleId),
        collectibleName: s(o.collectibleName),
        quantity: n(o.quantity),
        unitPrice: n(o.unitPrice),
        amount: n(o.totalPrice),
        source: s(o.source),
        status: ORDER_STATUS_MAP[o.status] || s(o.status),
        createTime: s(o.createdAt),
        payTime: s(o.paidAt)
      })),
      total: n(d.total)
    }
  }
}

/** 订单操作：cancel / markPaid / refund */
export function orderAction(id, action, extra = {}) {
  if (action === 'cancel') {
    return post(`/orders/${id}/cancel`, { reason: extra.reason || '管理员取消' })
  }
  if (action === 'markPaid') {
    return post(`/orders/${id}/mark-paid`, {
      payment_method: extra.payType || 'wallet',
      transaction_no: extra.transactionNo || ''
    })
  }
  if (action === 'refund') {
    return post(`/orders/${id}/refund`, { reason: extra.reason || '管理员发起退款' })
  }
  return Promise.resolve({ code: 4220, message: '不支持的操作', data: null })
}

export async function getRefundList(params) {
  const res = await get('/refunds', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((r) => {
        const rf = r.refund || r
        return {
          id: rf.id,
          orderNo: s(r.orderNo || rf.orderNo),
          userId: n(rf.userId),
          userName: s(r.username),
          userPhone: '',
          collectibleName: s(r.collectibleName),
          amount: n(rf.amount),
          reason: s(rf.reason),
          status: REFUND_STATUS[n(rf.status)] || 'pending',
          applyTime: s(rf.createdAt),
          handleTime: s(rf.updatedAt)
        }
      }),
      total: n(d.total)
    }
  }
}

/** 退款操作：approve(通过) / reject(驳回) / execute(执行退款) */
export function refundAction(id, action, extra = {}) {
  if (action === 'approve' || action === 'reject') {
    return post(`/refunds/${id}/approve`, {
      action,
      comment: extra.reason || extra.comment || ''
    })
  }
  if (action === 'execute') {
    return post(`/refunds/${id}/execute`, { refund_channel: extra.channel || 'original' })
  }
  return Promise.resolve({ code: 4220, message: '不支持的操作', data: null })
}

// ============================================================
// 寄售市场 / 求购 / 转赠
// ============================================================

export async function getResaleList(params) {
  const res = await get('/market/listings', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((r) => {
        const l = r.listing || r
        return {
          id: l.id,
          listingNo: `RS${String(l.id).padStart(8, '0')}`,
          userId: n(l.sellerId),
          sellerName: s(r.sellerName || r.uid),
          userPhone: '',
          collectibleId: n(l.collectibleId),
          collectibleName: s(r.collectibleName),
          cover: s(r.image),
          serial: s(r.serial),
          price: n(l.price),
          feeAmount: n(l.feeAmount),
          status: RESALE_STATUS[l.status] || s(l.status),
          createTime: s(l.listedAt || l.createdAt),
          delistReason: s(l.delistReason)
        }
      }),
      total: n(d.total)
    }
  }
}

/** 寄售挂单操作：freeze(冻结) / restore(恢复) / delist(强制下架) */
export function resaleAction(id, action, reason = '') {
  const actionMap = { freeze: 'freeze', restore: 'restore', delist: 'delist' }
  const a = actionMap[action] || action
  return post(`/market/listings/${id}/manage`, { action: a, reason })
}

/** 求购市场（后端暂无求购功能，返回空集） */
export function getBuyRequests() {
  return Promise.resolve({ code: 0, message: 'ok', data: { list: [], total: 0 } })
}
export function delistBuyRequest() {
  return Promise.resolve({ code: 4220, message: '求购功能暂未开放', data: null })
}

export async function getTransferList(params) {
  const res = await get('/transfers', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((t) => {
        const tr = t.transfer || t
        return {
          id: tr.id,
          fromUserId: n(tr.fromUserId),
          fromUser: s(t.fromName || t.fromUid),
          toUser: s(t.toName || t.toUid),
          toPhone: '',
          collectibleName: s(t.collectibleName),
          cover: s(t.image),
          serial: s(t.serial),
          status: TRANSFER_STATUS[tr.status] || s(tr.status),
          createTime: s(tr.createdAt)
        }
      }),
      total: n(d.total)
    }
  }
}

/** 转赠操作：revoke(撤销) */
export function transferAction(id, action, reason = '') {
  if (action === 'revoke') {
    return post(`/transfers/${id}/revoke`, { reason: reason || '管理员撤销' })
  }
  return Promise.resolve({ code: 4220, message: '不支持的操作', data: null })
}

// ============================================================
// 营销活动（活动化改造：签到/邀请/抽奖/合成均支持开关 + 新建活动）
// ============================================================

/** 签到活动配置（开关/名称/起止时间 + 奖励规则(六类) + 参与资格 + 发放方式 + 今日签到数） */
export async function getCheckinConfig() {
  const res = await get('/marketing/checkin')
  if (res.code !== 0) return res
  const d = res.data || {}
  // 新版奖励配置：{1:[{type,...}],...}（六类奖励）；旧版 {1:5,...,7:30}（纯司南币）兜底转换
  const rewardConfig = d.rewardConfig || {}
  const legacyRewards = d.rewards || {}
  const parseReward = (r) => {
    if (r && typeof r === 'object' && !Array.isArray(r)) return r
    return { type: 'points', amount: n(r) }
  }
  const dayKeys = new Set([...Object.keys(rewardConfig), ...Object.keys(legacyRewards)])
  const rules = [...dayKeys]
    .map((day) => {
      const rewards = rewardConfig[day]
        ? rewardConfig[day].map(parseReward)
        : (legacyRewards[day] !== undefined ? [parseReward(legacyRewards[day])] : [])
      return { day: Number(day), rewards }
    })
    .filter((r) => r.rewards.length)
    .sort((a, b) => a.day - b.day)
  // trend 形如 { '2026-09-01': 12, ... } → 本月签到数
  const trend = d.trend || {}
  const monthKey = new Date().toISOString().slice(0, 7)
  let monthCount = 0
  Object.keys(trend).forEach((k) => {
    if (s(k).slice(0, 7) === monthKey) monthCount += n(trend[k])
  })
  return {
    code: 0,
    message: res.message,
    data: {
      enabled: !!d.enabled,
      name: s(d.name) || '每日签到',
      startTime: s(d.startTime),
      endTime: s(d.endTime),
      todayCount: n(d.todayCount),
      monthCount,
      streakTop: [],
      rules,
      eligibility: { type: s(d.eligibilityType) || 'all', config: d.eligibilityConfig || {} },
      grantMode: s(d.grantMode) || 'realtime'
    }
  }
}

/** 签到活动开关（实时生效） */
export function toggleCheckin(enabled) {
  return post('/marketing/checkin', { enabled: enabled ? 1 : 0 })
}

/** 保存签到活动信息（名称/起止时间） */
export function saveCheckinActivity({ name, startTime, endTime }) {
  return post('/marketing/checkin', {
    name,
    start_time: startTime || '',
    end_time: endTime || ''
  })
}

/** 保存签到参与资格与发放方式（realtime 实时到账 / manual 记录名单统一发放） */
export function saveCheckinSettings({ eligibility, grantMode }) {
  return post('/marketing/checkin', {
    eligibility_type: eligibility?.type || 'all',
    eligibility_config: eligibility?.config || {},
    grant_mode: grantMode || 'realtime'
  })
}

/** 保存签到奖励规则（rules: [{day, rewards: [{type,...}]}]，六类奖励） */
export function saveCheckinRules(rules) {
  const rewardConfig = {}
  ;(rules || []).forEach((r) => {
    if (Array.isArray(r.rewards) && r.rewards.length) rewardConfig[r.day] = r.rewards
  })
  return post('/marketing/checkin', { reward_config: rewardConfig })
}

/** 抽奖活动列表（多活动：名称/状态/起止时间/参与资格/发放方式/奖池） */
export async function getLuckyDraws() {
  const res = await get('/marketing/lucky')
  if (res.code !== 0) return res
  const list = Array.isArray(res.data) ? res.data : []
  return {
    code: 0,
    message: res.message,
    data: list.map((a) => ({
      id: n(a.activityId),
      name: s(a.name),
      status: n(a.status) === 1 ? 'enabled' : 'disabled',
      startTime: s(a.startTime),
      endTime: s(a.endTime),
      eligibility: { type: s(a.eligibilityType) || 'all', config: a.eligibilityConfig || {} },
      grantMode: s(a.grantMode) || 'realtime',
      chancesIssued: n(a.drawCount),
      chancesUsed: n(a.drawCount),
      drawnCount: n(a.drawCount),
      probabilityOk: !!a.probabilityOk,
      prizes: (a.prizes || []).map((p) => ({
        id: p.id,
        tier: s(p.tierName),
        type: s(p.prizeType),
        name: s(p.prizeName) || (s(p.prizeType) === 'collectible' ? (s(p.collectibleName) || s(p.tierName)) : s(p.tierName)),
        cover: s(p.prizeImage || p.image),
        amount: n(p.coinAmount),
        total: n(p.total),
        won: n(p.won),
        probability: n(p.probability),
        collectibleId: n(p.collectibleId),
        rewardConfig: p.rewardConfig || null
      }))
    }))
  }
}

/** 新建/编辑抽奖活动（无 id 即新建；含参与资格 + 发放方式；enabled=false 时可空奖池保存） */
export function saveLuckyActivity({ id, name, enabled, startTime, endTime, eligibility, grantMode }) {
  return post('/marketing/lucky-activity', {
    id: id || null,
    name,
    status: enabled ? 1 : 0,
    start_time: startTime || null,
    end_time: endTime || null,
    eligibility_type: eligibility?.type || 'all',
    eligibility_config: eligibility?.config || {},
    grant_mode: grantMode || 'realtime'
  })
}

/** 抽奖活动开关（读当前活动信息连同开关一并提交；启用后端校验奖池完整） */
export async function toggleLuckyDraw(act) {
  const enabling = act.status !== 'enabled'
  try {
    return await saveLuckyActivity({
      id: act.id,
      name: act.name,
      enabled: enabling,
      startTime: act.startTime,
      endTime: act.endTime,
      eligibility: act.eligibility,
      grantMode: act.grantMode
    })
  } catch (e) {
    return { code: -1, message: '操作失败', data: null }
  }
}

/**
 * 保存抽奖奖项池（整池覆盖式提交，概率合计需为 1）
 * prizes: [{id?, tier, name, cover, type, rewardConfig?, total, probability}]
 * 奖项类型：collectible/points/draw_chance/priority_qualification/eligibility_qualification/blindbox/none
 */
export function saveLuckyPrizes(activityId, prizes) {
  return post('/marketing/lucky', {
    activity_id: activityId,
    prizes: (prizes || []).map((p, idx) => ({
      id: p.id || null,
      tier_name: p.tier,
      prize_name: p.name || '',
      prize_image: p.cover || '',
      prize_type: p.type,
      reward_config: p.rewardConfig || (p.type === 'collectible' ? { collectibleId: p.collectibleId, quantity: 1 } : {}),
      total: p.total,
      probability: p.probability,
      sort_order: idx
    }))
  })
}

/** 合成活动列表（含参与资格 + 发放方式 + 产出数量） */
export async function getSynthesisList(params) {
  const res = await get('/marketing/synthesis', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((a) => {
        const act = a.synthesisActivity || a
        let eligibilityConfig = act.eligibilityConfig
        if (typeof eligibilityConfig === 'string') {
          try { eligibilityConfig = JSON.parse(eligibilityConfig || 'null') } catch (e) { eligibilityConfig = {} }
        }
        return {
          id: act.id,
          title: s(act.title),
          // 后端存储 limit/permanent → 前端语义 limited/permanent
          type: s(act.type) === 'limit' ? 'limited' : 'permanent',
          status: n(act.status) === 1 ? 'enabled' : 'disabled',
          startTime: s(act.startTime),
          endTime: s(act.endTime),
          rules: s(act.rules),
          materials: (a.materials || []).map((m) => ({
            collectibleId: n(m.collectibleId),
            name: s(m.name),
            cover: s(m.image),
            count: n(m.count)
          })),
          result: {
            collectibleId: n(act.resultCollectibleId),
            name: s(a.resultName),
            cover: s(a.resultImage),
            quantity: n(act.resultQuantity) || 1
          },
          perUserLimit: n(act.perUserLimit),
          totalLimit: act.totalLimit,
          usedCount: n(act.usedCount),
          eligibility: { type: s(act.eligibilityType) || 'all', config: eligibilityConfig || {} },
          grantMode: s(act.grantMode) || 'realtime'
        }
      }),
      total: n(d.total)
    }
  }
}

/** 合成记录列表（多合/错合定位与对账；筛选 activityId/userId/startDate/endDate） */
export async function getSynthesisRecords(params = {}) {
  const res = await get('/marketing/synthesis-records', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((r) => ({
        id: n(r.id),
        userId: n(r.userId),
        username: s(r.username),
        phone: s(r.phone),
        activityId: n(r.activityId),
        activityTitle: s(r.activityTitle),
        perUserLimit: n(r.perUserLimit),
        resultId: n(r.resultUserCollectibleId),
        resultSerial: s(r.resultSerial),
        resultStatus: s(r.resultStatus),
        resultName: s(r.resultName),
        resultCover: s(r.resultImage),
        userActivityCount: n(r.userActivityCount),
        consumed: (r.consumed || []).map((c) => ({ id: n(c.userCollectibleId), serial: s(c.serial), name: s(c.name) })),
        createTime: s(r.createdAt)
      })),
      total: n(d.total)
    }
  }
}

/** 合成活动开关（读当前完整配置连同 status 一并提交） */
export async function toggleSynthesis(id) {
  const cur = await getSilentSafe2('/marketing/synthesis')
  if (cur.code !== 0) return cur
  const raw = cur.data || {}
  const item = (raw.list || []).find((x) => n(x.id) === Number(id))
  if (!item) return { code: 4040, message: '活动不存在', data: null }
  let eligibilityConfig = item.eligibilityConfig
  if (typeof eligibilityConfig === 'string') {
    try { eligibilityConfig = JSON.parse(eligibilityConfig || 'null') } catch (e) { eligibilityConfig = {} }
  }
  return post('/marketing/synthesis', {
    id: item.id,
    type: s(item.type),
    title: s(item.title),
    rules: s(item.rules),
    result_collectible_id: n(item.resultCollectibleId),
    result_quantity: n(item.resultQuantity) || 1,
    materials: (item.materials || []).map((m) => ({ collectible_id: n(m.collectibleId), count: n(m.count) })),
    per_user_limit: n(item.perUserLimit),
    total_limit: item.totalLimit,
    eligibility_type: s(item.eligibilityType) || 'all',
    eligibility_config: eligibilityConfig || {},
    grant_mode: s(item.grantMode) || 'realtime',
    status: n(item.status) === 1 ? 0 : 1
  })
}

/** 新建/编辑合成活动（payload.id 为空即新建；含参与资格 + 发放方式；前端语义 limited → 后端存储 limit） */
export function saveSynthesis(payload) {
  const typeRaw = payload.type === 'limited' ? 'limit' : (payload.type === 'limit' ? 'limit' : 'permanent')
  return post('/marketing/synthesis', {
    id: payload.id,
    type: typeRaw,
    title: payload.title,
    rules: payload.rules,
    result_collectible_id: payload.result?.collectibleId || payload.result_collectible_id,
    result_quantity: payload.result?.quantity || payload.result_quantity || 1,
    materials: (payload.materials || []).map((m) => ({ collectible_id: m.collectibleId, count: m.count })),
    per_user_limit: payload.perUserLimit,
    total_limit: payload.totalLimit,
    start_time: payload.startTime,
    end_time: payload.endTime,
    image: payload.result?.cover,
    eligibility_type: payload.eligibility?.type || 'all',
    eligibility_config: payload.eligibility?.config || {},
    grant_mode: payload.grantMode || 'realtime',
    status: payload.status === 'disabled' ? 0 : 1
  })
}

/** 邀请活动列表（多活动；档位奖励 + 被邀请人奖励/完成条件 + 发放方式） */
export async function getInviteList() {
  const res = await get('/marketing/invite')
  if (res.code !== 0) return res
  const rows = Array.isArray(res.data) ? res.data : []
  const parseJsonField = (v, fallback) => {
    if (v === null || v === undefined) return fallback
    if (typeof v !== 'string') return v
    try { return JSON.parse(v) } catch (e) { return fallback }
  }
  return {
    code: 0,
    message: res.message,
    data: rows.map((a) => ({
      id: n(a.id),
      name: s(a.name),
      status: s(a.status) === 'enabled' ? 'enabled' : 'disabled',
      mode: s(a.airdropMode),
      startTime: s(a.startTime),
      endTime: s(a.endTime),
      totalLimit: a.totalLimit,
      usedCount: n(a.usedCount),
      inviterReward: { collectibleId: n(a.inviterCollectibleId), name: s(a.inviterCollectibleName), quantity: n(a.inviterQuantity) },
      inviteeReward: { collectibleId: n(a.inviteeCollectibleId), name: s(a.inviteeCollectibleName), quantity: n(a.inviteeQuantity) },
      // 新版：档位奖励（1-50人）+ 被邀请人奖励配置 + 完成条件
      tiers: parseJsonField(a.tiers, []) || [],
      inviteeRewardConfig: parseJsonField(a.inviteeRewardConfig, null),
      inviteeConditions: parseJsonField(a.inviteeConditions, []) || [],
      grantMode: s(a.grantMode) || 'realtime',
      stats: { invitedCount: n(a.inviteeCount) },
      description: s(a.description)
    }))
  }
}

/** 兼容旧引用：单活动概览取第一个 */
export async function getInviteActivity() {
  const res = await getInviteList()
  if (res.code !== 0) return res
  return { code: 0, message: res.message, data: res.data[0] || null }
}

/**
 * 新建/编辑邀请活动（无 id 即新建）
 * 新版字段：tiers:[{inviteCount, rewards:[{type,...}]}]、inviteeRewardConfig、
 *          inviteeConditions:['realname','wallet','checkin','consume']、grantMode
 * 兼容旧字段：inviterReward/inviteeReward（空投藏品）
 */
export function saveInviteActivity(payload) {
  return post('/marketing/invite', {
    id: payload.id,
    name: payload.name,
    status: payload.status === 'enabled' ? 'enabled' : 'disabled',
    inviter_collectible_id: payload.inviterReward?.collectibleId || payload.inviter_collectible_id,
    inviter_quantity: payload.inviterReward?.quantity || payload.inviter_quantity || 1,
    invitee_collectible_id: payload.inviteeReward?.collectibleId || payload.invitee_collectible_id,
    invitee_quantity: payload.inviteeReward?.quantity || payload.invitee_quantity || 1,
    airdrop_mode: payload.mode || 'realtime',
    tiers: payload.tiers || [],
    invitee_reward_config: payload.inviteeRewardConfig || null,
    invitee_conditions: payload.inviteeConditions || [],
    grant_mode: payload.grantMode || 'realtime',
    start_time: payload.startTime || null,
    end_time: payload.endTime || null,
    total_limit: payload.totalLimit || null,
    description: payload.description || ''
  })
}

/** 邀请活动开关（读当前活动信息连同开关一并提交） */
export async function toggleInviteActivity(act) {
  return saveInviteActivity({
    ...act,
    status: act.status === 'enabled' ? 'disabled' : 'enabled'
  })
}

// ============================================================
// 注册活动（注册实名前 N 名档位奖励）
// ============================================================

/** 注册活动列表（含档位/已发放人数/平台累计实名人数） */
export async function getRegisterActivities() {
  const res = await get('/marketing/register')
  if (res.code !== 0) return res
  const rows = Array.isArray(res.data) ? res.data : []
  return {
    code: 0,
    message: res.message,
    data: rows.map((a) => ({
      id: n(a.id),
      name: s(a.name),
      status: s(a.status) === 'enabled' ? 'enabled' : 'disabled',
      startTime: s(a.startTime),
      endTime: s(a.endTime),
      tiers: a.tiersParsed || [],
      grantMode: s(a.grantMode) || 'realtime',
      grantedCount: n(a.grantedCount),
      realnameCount: n(a.realnameCount),
      usedCount: n(a.usedCount),
      description: s(a.description)
    }))
  }
}

/**
 * 新建/编辑注册活动（无 id 即新建）
 * tiers: [{rankLimit: N, rewards: [{type,...}]}]（实名前 N 名，多档位）
 */
export function saveRegisterActivity(payload) {
  return post('/marketing/register-save', {
    id: payload.id || null,
    name: payload.name,
    status: payload.status === 'enabled' ? 'enabled' : 'disabled',
    start_time: payload.startTime || null,
    end_time: payload.endTime || null,
    tiers: payload.tiers || [],
    grant_mode: payload.grantMode || 'realtime',
    description: payload.description || ''
  })
}

/** 删除注册活动（已产生发放记录的活动后端自动转为停用） */
export function deleteRegisterActivity(id) {
  return post('/marketing/register-delete', { id })
}

// ============================================================
// 奖励名单（manual 发放方式的活动：记录名单 → 导出/统一发放）
// ============================================================

/**
 * 奖励名单查询
 * params: { page, pageSize, activityType?, activityId?, status?, userId?, phone?, keyword? }
 */
export async function getRewardRecords(params) {
  const p = { ...params }
  if (p.activityType) p.activity_type = p.activityType
  if (p.activityId) p.activity_id = p.activityId
  delete p.activityType
  delete p.activityId
  const res = await get('/marketing/reward-records', p)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((r) => ({
        id: n(r.id),
        activityType: s(r.activityType),
        activityId: n(r.activityId),
        activityTitle: s(r.activityTitle),
        userId: n(r.userId),
        phone: s(r.phone),
        rewardType: s(r.rewardType),
        rewardLabel: s(r.rewardLabel),
        status: s(r.status),
        issueResult: s(r.issueResult),
        issuedAt: s(r.issuedAt),
        createdAt: s(r.createdAt)
      })),
      total: n(d.total),
      stats: d.stats || {}
    }
  }
}

/** 奖励名单导出 CSV（同查询筛选参数；带令牌的 blob 下载） */
export async function exportRewardRecords(params) {
  const p = { ...params }
  if (p.activityType) p.activity_type = p.activityType
  if (p.activityId) p.activity_id = p.activityId
  delete p.activityType
  delete p.activityId
  const token = localStorage.getItem('sinan_admin_token')
  const resp = await http.get('/marketing/reward-records/export', {
    params: p,
    responseType: 'blob',
    headers: token ? { Authorization: `Bearer ${token}` } : {}
  })
  const url = URL.createObjectURL(new Blob([resp]))
  const a = document.createElement('a')
  a.href = url
  a.download = `奖励名单_${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '')}.csv`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
  return { code: 0, message: 'ok', data: null }
}

/** 待发放名单统一发放（activityType/activityId/recordIds 任选其一分批） */
export function issueRewardRecords({ activityType, activityId, recordIds }) {
  return post('/marketing/reward-records/issue', {
    activity_type: activityType || '',
    activity_id: activityId || null,
    record_ids: recordIds || []
  })
}

/** 优先购活动列表 */
export async function getPrioritySales() {
  const res = await get('/marketing/priority', { page: 1, pageSize: 50 })
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: (d.list || []).map((a) => {
      const act = a.priorityActivity || a
      return {
        id: act.id,
        name: s(act.name),
        type: 'priority',
        collectibleId: n(act.collectibleId),
        collectibleName: s(a.collectibleName),
        cover: s(a.image),
        startTime: s(act.startTime),
        endTime: s(act.endTime),
        status: s(act.status),
        whitelistCount: n(a.whitelistCount),
        whitelists: []
      }
    })
  }
}

/** 优先购白名单添加 */
export function addWhitelist({ saleId, phone, quantity, expiresAt }) {
  return post('/marketing/priority', {
    id: saleId,
    whitelist: [{ phone, max_quantity: quantity, expires_at: expiresAt }]
  })
}

/** 清理过期白名单（后端在读取时自动过滤，此处刷新即可） */
export function cleanExpiredPriority(saleId) {
  return get('/marketing/priority', { page: 1, pageSize: 50 })
}

// ============================================================
// 分解熔炼活动
// ============================================================

/** 分解规则列表 */
export async function getDecomposeRules(params) {
  const res = await get('/decompose/rules', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((r) => ({
        id: n(r.id),
        name: s(r.name),
        sourceCollectibleId: n(r.sourceCollectibleId),
        sourceName: s(r.sourceName),
        sourceImage: s(r.sourceImage),
        enabled: n(r.enabled),
        perUserLimit: n(r.perUserLimit),
        dailyLimit: n(r.dailyLimit),
        startTime: s(r.startTime),
        endTime: s(r.endTime),
        items: (r.items || []).map((i) => ({
          id: n(i.id),
          collectibleId: n(i.resultCollectibleId),
          name: s(i.name),
          cover: s(i.image),
          quantityPer: n(i.quantityPer)
        }))
      })),
      total: n(d.total)
    }
  }
}

/** 保存分解规则（新建/编辑） */
export function saveDecomposeRule(payload) {
  return post('/decompose/rules', {
    ...(payload.id ? { id: payload.id } : {}),
    name: payload.name,
    sourceCollectibleId: payload.sourceCollectibleId,
    enabled: payload.enabled ? 1 : 0,
    perUserLimit: n(payload.perUserLimit),
    dailyLimit: n(payload.dailyLimit),
    startTime: payload.startTime || '',
    endTime: payload.endTime || '',
    items: (payload.items || []).map((i) => ({
      collectibleId: i.collectibleId,
      quantityPer: n(i.quantityPer)
    }))
  })
}

/** 分解规则开关 */
export function toggleDecomposeRule(id) {
  return post(`/decompose/rules/${id}/toggle`)
}

/** 删除分解规则 */
export function deleteDecomposeRule(id) {
  return del(`/decompose/rules/${id}`)
}

// ============================================================
// 资格购管理
// ============================================================

export async function getQualifications() {
  const res = await get('/collectibles/qualifications')
  if (res.code !== 0) return res
  return {
    code: 0,
    message: res.message,
    data: (res.data || []).map((q) => ({
      id: q.id,
      collectibleId: n(q.collectibleId),
      collectibleName: s(q.collectibleName),
      cover: s(q.collectibleImage),
      price: n(q.price),
      isEnabled: n(q.isEnabled) === 1,
      conditionType: n(q.conditionType),
      requiredCollectibleIds: q.requiredCollectibleIds || [],
      requiredCheckinDays: n(q.requiredCheckinDays),
      requiredInviteCount: n(q.requiredInviteCount),
      validStartAt: s(q.validStartAt),
      validEndAt: s(q.validEndAt),
      whitelistCount: n(q.whitelistCount),
      whitelist: (q.whitelist || []).map((w) => ({
        id: w.id,
        userId: n(w.userId),
        nickname: s(w.nickname),
        phone: s(w.phone),
        expiresAt: s(w.expiresAt)
      }))
    }))
  }
}

/** 保存资格购配置（condition_type: 1 满足任一 / 2 满足全部） */
export function saveQualification(payload) {
  return post(`/collectibles/${payload.collectibleId}/qualification`, {
    id: payload.collectibleId,
    is_enabled: payload.isEnabled ? 1 : 0,
    condition_type: payload.conditionType,
    required_collectible_ids: payload.requiredCollectibleIds || [],
    required_checkin_days: n(payload.requiredCheckinDays),
    required_invite_count: n(payload.requiredInviteCount),
    valid_start_at: payload.validStartAt || '',
    valid_end_at: payload.validEndAt || ''
  })
}

export function addQualificationWhitelist({ qualificationId, phones, expiresAt }) {
  return post('/collectibles/qualification-whitelist', {
    config_id: qualificationId,
    phones,
    expires_at: expiresAt || ''
  })
}

export function removeQualificationWhitelist(qualificationId, whitelistId) {
  return del(`/collectibles/qualification-whitelist/${whitelistId}`)
}

// ============================================================
// 钱包流水
// ============================================================

export async function getWalletTransactions(params) {
  const res = await get('/wallet/transactions', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((t) => {
        const tx = t.transaction || t
        return {
          id: tx.id,
          userId: n(tx.userId),
          userName: s(t.username),
          userPhone: '',
          type: TX_TYPE_MAP[tx.transType] || s(tx.transType),
          title: s(tx.title),
          direction: n(tx.direction),
          amount: n(tx.amount),
          balanceAfter: n(tx.balanceAfter),
          createTime: s(tx.createdAt)
        }
      }),
      total: n(d.total)
    }
  }
}

export async function getWalletStats() {
  const res = await get('/wallet/stats')
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      todayRecharge: n(d.todayRecharge),
      todayConsume: n(d.todayConsume),
      todayReward: n(d.todayReward),
      monthRecharge: n(d.monthRecharge),
      totalBalance: n(d.totalBalance),
      totalFrozen: n(d.totalFrozen)
    }
  }
}

// ============================================================
// 风控 / 工单
// ============================================================

export async function getRiskAlerts(params) {
  const res = await get('/security/risk-alerts', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((a) => ({
        id: a.id,
        type: s(a.alertType),
        level: { 1: 'low', 2: 'medium', 3: 'high' }[n(a.alertLevel)] || 'low',
        userName: s(a.username || a.uid),
        userPhone: s(a.phone),
        detail: s(a.title),
        status: { 1: 'pending', 2: 'processing', 3: 'resolved' }[n(a.status)] || 'pending',
        createTime: s(a.createdAt),
        handleTime: s(a.handledAt),
        handler: s(a.handlerName),
        result: s(a.handleComment)
      })),
      total: n(d.total)
    }
  }
}

/** 处理风控告警：result = resolved(处理) / ignored(忽略) / processing(跟进) */
export function handleRiskAlert({ id, result, comment = '' }) {
  const statusMap = { resolved: 3, ignored: 4, processing: 2 }
  return post(`/security/risk-alerts/${id}/handle`, {
    status: statusMap[result] || 3,
    handle_comment: comment
  })
}

export async function getTickets(params) {
  const res = await get('/tickets', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((t) => ({
        id: t.id,
        ticketNo: `TK${String(t.id).padStart(10, '0')}`,
        userName: s(t.username || t.uid),
        userPhone: s(t.phone),
        type: s(t.ticketType),
        priority: s(t.priority),
        title: s(t.title),
        status: s(t.status),
        createTime: s(t.createdAt),
        replyCount: n(t.replyCount)
      })),
      total: n(d.total)
    }
  }
}

export function replyTicket({ id, content }) {
  return post(`/tickets/${id}/reply`, { content, is_internal: 0 })
}

export function closeTicket(id) {
  return post(`/tickets/${id}/status`, { status: 'closed' })
}

// ============================================================
// 内容管理
// ============================================================

export async function getAnnouncements(params) {
  const res = await get('/cms/announcements', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((a) => ({
        id: a.id,
        title: s(a.title),
        type: s(a.type) === 'notice' ? 'system' : s(a.subtype) === 'activity' ? 'activity' : 'system',
        status: s(a.status) || 'published',
        publishTime: s(a.publish_time),
        createdAt: s(a.created_at),
        summary: s(a.summary),
        content: s(a.content),
        isTop: n(a.isTop) === 1
      })),
      total: n(d.total)
    }
  }
}

export function saveAnnouncement(payload) {
  const body = {
    title: payload.title,
    type: payload.type === 'activity' ? 'notice' : 'notice',
    subtype: payload.type === 'activity' ? 'activity' : 'operation',
    summary: payload.summary || '',
    content: payload.content || '',
    status: payload.status || 'draft',
    publish_time: payload.publishTime || '',
    is_top: payload.isTop ? 1 : 0
  }
  if (payload.id) {
    body.id = payload.id
    return put(`/cms/announcements/${payload.id}`, body)
  }
  return post('/cms/announcements', body)
}

export function removeAnnouncement(id) {
  return del(`/cms/announcements/${id}`)
}

export async function getBanners() {
  const res = await get('/cms/banners', { page: 1, pageSize: 50 })
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: (d.list || []).map((b) => ({
      id: b.id,
      image: s(b.image),
      title: s(b.description),
      link: '',
      sort: n(b.sortOrder),
      status: n(b.isActive)
    }))
  }
}

export function saveBanner(payload) {
  const body = {
    image: payload.image,
    description: payload.title || '',
    sort_order: n(payload.sort),
    is_active: payload.status
  }
  if (payload.id) {
    body.id = payload.id
    return put(`/cms/banners/${payload.id}`, body)
  }
  return post('/cms/banners', body)
}

export async function getCommunityGroups() {
  const res = await get('/cms/community')
  if (res.code !== 0) return res
  return {
    code: 0,
    message: res.message,
    data: (res.data || []).map((g) => ({
      id: g.id,
      icon: s(g.icon),
      name: s(g.name),
      description: s(g.description),
      qrCode: s(g.qrCode),
      members: n(g.members),
      isActive: n(g.isActive),
      sort: n(g.sort)
    }))
  }
}

export function saveCommunityGroup(payload) {
  const body = {
    name: payload.name,
    description: payload.description || '',
    icon: payload.icon || '/images/tab/tab-bell.png',
    qr_code: payload.qrCode || '',
    members: n(payload.members),
    sort: n(payload.sort),
    is_active: payload.isActive
  }
  if (payload.id) {
    body.id = payload.id
    return put(`/cms/community/${payload.id}`, body)
  }
  return post('/cms/community', body)
}

export async function getArtifacts(params) {
  const res = await get('/cms/artifacts', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((a) => ({
        id: a.id,
        name: s(a.name),
        dynasty: s(a.dynasty),
        image: s(a.image),
        imgHeight: n(a.img_height) || 150,
        museum: s(a.museum),
        level: s(a.level),
        material: s(a.material),
        status: n(a.status)
      })),
      total: n(d.total)
    }
  }
}

export function saveArtifact(payload) {
  const body = {
    name: payload.name,
    dynasty: payload.dynasty || '',
    image: payload.image || '',
    img_height: payload.imgHeight || 150,
    material: payload.material || '',
    museum: payload.museum || '',
    level: payload.level || '',
    ...(payload.status !== undefined ? { status: payload.status } : {})
  }
  if (payload.id) {
    body.id = payload.id
    return put(`/cms/artifacts/${payload.id}`, body)
  }
  return post('/cms/artifacts', body)
}

// ============================================================
// 区块链（三链：文昌链 / 联盟链 / 蚂蚁链）
// ============================================================

/** 链网络配置（上链配置页数据源） */
export async function getChainNetworks() {
  const res = await get('/chain/networks')
  if (res.code !== 0) return res
  return res
}

/** 保存链网络配置（密钥非空才更新） */
export function saveChainNetwork(payload) {
  return put(`/chain/networks/${payload.id}`, {
    env: payload.env,
    rpc_url: payload.rpcUrl || '',
    chain_id: payload.chainId || '',
    explorer_url: payload.explorerUrl || '',
    gas_strategy: payload.gasStrategy || 'medium',
    status: payload.status,
    is_default: payload.isDefault ? 1 : 0,
    api_key: payload.apiKey || '',
    api_secret: payload.apiSecret || '',
    remark: payload.remark || ''
  })
}

/** 连通性测试 */
export function testChainNetwork(id) {
  return post(`/chain/networks/${id}/test`)
}

export async function getChainContracts(params) {
  const res = await get('/chain/contracts', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((c) => ({
        id: c.id,
        networkId: n(c.networkId),
        chainType: s(c.chainCode),
        chainEnv: s(c.env),
        contractName: s(c.contractName),
        contractAddress: s(c.contractAddress),
        contractType: s(c.contractType),
        status: n(c.status),
        createTime: s(c.createdAt)
      })),
      total: n(d.total)
    }
  }
}

export function toggleChainContract(id) {
  return post(`/chain/contracts/${id}/toggle`)
}

export function saveChainContract(payload) {
  const body = {
    network_id: payload.networkId,
    contract_name: payload.contractName,
    contract_address: payload.contractAddress,
    contract_type: payload.contractType || 'nft',
    token_standard: payload.tokenStandard || 'ERC721',
    description: payload.description || ''
  }
  if (payload.id) {
    body.id = payload.id
    return put(`/chain/contracts/${payload.id}`, body)
  }
  return post('/chain/contracts', body)
}

export async function getChainTransactions(params) {
  const res = await get('/chain/transactions', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((t) => ({
        id: t.id,
        txHash: s(t.txHash),
        type: s(t.txType),
        contractName: s(t.contract),
        userName: s(t.username || t.uid),
        token: `${s(t.collectibleName)} #${s(t.serial)}`,
        gas: s(t.gas),
        status: s(t.txStatus) === 'success' ? 'success' : s(t.txStatus) === 'pending' ? 'pending' : 'failed',
        blockTime: s(t.updatedAt)
      })),
      total: n(d.total)
    }
  }
}

/** 手动补铸（上链失败重试） */
export function mintOnChain(id) {
  return post(`/chain/mint/${id}`)
}

// ============================================================
// 审批中心
// ============================================================

export async function getApprovals(params) {
  const res = await get('/approvals', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((a) => ({
        id: a.id,
        type: s(a.type),
        title: s(a.title),
        applicant: s(a.applicantName),
        status: s(a.status),
        createTime: s(a.createdAt),
        handleTime: s(a.updatedAt),
        reason: s(a.detail)
      })),
      total: n(d.total)
    }
  }
}

/** 审批处理：action = approve / reject */
export function handleApproval({ id, action, reason = '' }) {
  return post(`/approvals/${id}/handle`, { action, reason })
}

// ============================================================
// 统计报表（报表中心：销售/用户/藏品/盲盒/财务 五大报表）
// 后端按报表维度独立输出：summary / trend / 明细数组
// 适配层统一 snake_case → camelCase（视图零侵入）
// ============================================================

/** 通用数组字段驼峰化（递归一层） */
const camelizeList = (list) =>
  (list || []).map((row) => Object.fromEntries(
    Object.entries(row || {}).map(([k, v]) => [k.replace(/_([a-z])/g, (_, c) => c.toUpperCase()), v])
  ))

/**
 * 销售报表：GMV/订单趋势、来源分布、支付方式、TOP 藏品
 * GET /reports/sales?start_date=&end_date=
 */
export async function getSalesReport(params = {}) {
  const res = await get('/reports/sales', reportParams(params))
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    ...res,
    data: {
      ...d,
      trend: camelizeList(d.trend),
      topCollectibles: camelizeList(d.topCollectibles)
    }
  }
}

/** 用户报表：注册趋势、实名率、持仓分层、邀请 TOP */
export async function getUserReport(params = {}) {
  const res = await get('/reports/users', reportParams(params))
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    ...res,
    data: {
      ...d,
      regTrend: camelizeList(d.regTrend),
      topInviters: camelizeList(d.topInviters)
    }
  }
}

/** 藏品报表：发售/流通/库存分布、市场寄售概览 */
export async function getCollectibleReport(params = {}) {
  const res = await get('/reports/collectibles', reportParams(params))
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    ...res,
    data: {
      ...d,
      categories: camelizeList(d.categories),
      issueTrend: camelizeList(d.issueTrend),
      topCirculate: camelizeList(d.topCirculate)
    }
  }
}

/** 盲盒报表：开盒趋势、奖池分布、开盒排行 */
export async function getBlindboxReport(params = {}) {
  const res = await get('/reports/blindbox', reportParams(params))
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    ...res,
    data: {
      ...d,
      openTrend: camelizeList(d.openTrend),
      prizes: camelizeList(d.prizes),
      boxRanking: camelizeList(d.boxRanking)
    }
  }
}

/** 财务对账：资金总账、渠道对账、全站余额快照 */
export function getFinanceReport(params = {}) {
  return get('/reports/finance', reportParams(params))
}

// ============================================================
// 数据快照（用户持仓快照 / 交易快照，手动触发生成、幂等重跑）
// ============================================================

/** 触发生成快照：date 基准日（缺省今天）；userId 指定用户（缺省全部） */
export function generateSnapshots({ date = '', userId = null } = {}) {
  const body = { date }
  if (userId) body.userId = userId
  return post('/snapshots/generate', body)
}

/** 持仓快照列表（date/userId/collectibleId/page/pageSize） */
export function getHoldingsSnapshots(params = {}) {
  return get('/snapshots/holdings', params)
}

/** 交易快照列表（date/userId/page/pageSize） */
export function getTradeSnapshots(params = {}) {
  return get('/snapshots/trades', params)
}

/** 已生成快照的日期清单（生成入口快捷选择） */
export async function getSnapshotDates() {
  const res = await get('/snapshots/dates')
  if (res.code !== 0) return { code: 0, message: 'ok', data: [] }
  return res
}

/** 报表时间参数归一（dateRange 组件输出 [start, end]） */
function reportParams({ range } = {}) {
  if (!range || !range.length) return {}
  const [start, end] = range
  return {
    start_date: start || '',
    end_date: end || ''
  }
}

/** 旧视图兼容入口（看板页仍引用：聚合销售+用户两表） */
export async function getStatistics() {
  const [sales, users] = await Promise.all([
    getSilentSafe('/reports/sales', {}),
    getSilentSafe('/reports/users', {})
  ])
  if (sales.code !== 0) return sales
  const sd = sales.data || {}
  const ud = users.data || {}
  const trend = camelizeList(sd.trend).slice(-7)
  const tops = camelizeList(sd.topCollectibles).slice(0, 5)
  return {
    code: 0,
    message: 'ok',
    data: {
      dau: n(ud.summary?.holdingUsers),
      dauTrend: trend.map((t) => n(t.orderCount)),
      retention: [
        { label: '实名率', value: n(ud.summary?.realnameRate) },
        { label: '持仓用户', value: n(ud.summary?.holdingUsers) },
        { label: '冻结用户', value: n(ud.summary?.frozen) }
      ],
      finance: {
        monthIncome: n(sd.summary?.gmvTotal),
        monthFee: 0,
        monthRecharge: 0,
        monthWithdraw: 0,
        incomeTrend: trend.map((t) => ({ date: (t.statDate || '').slice(5), value: n(t.gmv) })),
        feeShare: [{ label: '销售 GMV', value: n(sd.summary?.gmvTotal) }]
      },
      salesRank: tops.map((c) => ({
        name: c.name,
        sold: n(c.quantity),
        amount: n(c.gmv),
        cover: c.coverImage
      })),
      userTrend: trend.map((t) => ({ date: (t.statDate || '').slice(5), value: n(t.orderCount) }))
    }
  }
}

// ============================================================
// 数据审计（恒等式校验：库存 / 订单 / 资金）
// ============================================================

/** 库存审计：藏品库存恒等式（发行量 = 已售+锁定+预留+空投+销毁+库存池） */
export function getInventoryAudit() {
  return get('/collectibles/audit')
}

/** 订单审计：过期未支付 / 完成无支付 / 持仓缺失 / 退款状态悬空 */
export function getOrderAudit() {
  return get('/orders/audit')
}

/** 资金审计：资金守恒恒等式 + 逐用户流水核对 */
export function getWalletAudit() {
  return get('/wallet/audit')
}

// ============================================================
// 系统配置：短信 / 支付渠道 / 安全策略
// ============================================================

/**
 * 短信配置（密钥永不回显明文，仅掩码）
 * 返回：{ provider, isEnabled, dailyLimit, accessKeyMasked, signature, template*, configured, lastTest* }
 */
export function getSmsConfig() {
  return get('/system/sms-config')
}

/**
 * 保存短信配置（密钥传空 = 保持不变）
 * { provider, isEnabled, dailyLimit, signature, templateRegister/Login/Reset, accessKey, accessSecret }
 */
export function saveSmsConfig(payload) {
  return put('/system/sms-config', {
    provider: payload.provider,
    is_enabled: payload.isEnabled ? 1 : 0,
    daily_limit: n(payload.dailyLimit),
    signature: payload.signature || '',
    template_register: payload.templateRegister || '',
    template_login: payload.templateLogin || '',
    template_reset: payload.templateReset || '',
    access_key: payload.accessKey || '',
    access_secret: payload.accessSecret || ''
  })
}

/** 发送测试短信 */
export function testSms(phone) {
  return post('/system/sms-config/test', { phone })
}

/**
 * 支付渠道列表（第三方钱包配置；密钥仅 configExists + 脱敏摘要）
 * 返回：[{ id, channelCode, channelName, feeRate, status, isRecommended, sortOrder, configExists, configMasked }]
 */
export function getPaymentChannels() {
  return get('/system/payment-channels')
}

/**
 * 保存支付渠道（启用第三方渠道前必须先配置密钥）
 * { id, channelName?, feeRate?, status?, isRecommended?, sortOrder?, config?, remark? }
 */
export function savePaymentChannel(payload) {
  const body = {
    channel_name: payload.channelName,
    fee_rate: payload.feeRate,
    is_recommended: payload.isRecommended ? 1 : 0,
    sort_order: n(payload.sortOrder),
    remark: payload.remark || ''
  }
  if (payload.status !== undefined) body.status = payload.status ? 1 : 0
  // config：JSON 对象（整体加密落库）；undefined = 不变更，null/'' = 清除
  if (payload.config !== undefined) body.config = payload.config
  return put(`/system/payment-channels/${payload.id}`, body)
}

/**
 * 安全策略（含管理员账号安全概览）
 * 返回：{ configs: [{key,name,value}], overview: { adminTotal, adminLocked, adminDisabled, login24hFail } }
 */
export function getSecurityConfig() {
  return get('/system/security-config')
}

/** 保存安全策略参数（白名单键：admin_login_fail_limit 等） */
export function saveSecurityConfig(key, value) {
  return put(`/system/security-config/${key}`, { value: String(value) })
}

// ============================================================
// 系统管理（管理员/角色/日志/站点配置/平台清库）
// ============================================================

export async function getAdmins(params) {
  const res = await get('/permission/admins', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((a) => ({
        id: a.id,
        username: s(a.username),
        name: s(a.realName),
        role: s(a.roleCode),
        avatar: '',
        status: n(a.status) === 1 ? 'enabled' : 'disabled',
        lastLoginTime: s(a.lastLoginAt),
        phone: s(a.phone),
        isLocked: !!a.isLocked
      })),
      total: n(d.total)
    }
  }
}

export async function getRoles() {
  const [roles, tree] = await Promise.all([
    getSilentSafe('/permission/roles'),
    getSilentSafe('/permission/tree')
  ])
  if (roles.code !== 0) return roles
  return {
    code: 0,
    message: 'ok',
    data: {
      roles: (roles.data || []).map((r) => ({
        id: r.id,
        key: s(r.code),
        name: s(r.name),
        desc: s(r.description),
        members: n(r.adminCount),
        permissions: []
      })),
      tree: (tree.data || []).map((p) => ({ label: s(p.name), key: s(p.code), children: (p.children || []).map((c) => ({ label: s(c.name), key: s(c.code) })) }))
    }
  }
}

export async function getLoginLogs(params) {
  const res = await get('/permission/login-logs', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((l) => ({
        id: l.id,
        username: s(l.username),
        name: s(l.realName),
        ip: s(l.ip),
        location: '',
        result: n(l.status) === 1 ? 'success' : 'fail',
        time: s(l.createdAt)
      })),
      total: n(d.total)
    }
  }
}

export async function getOperationLogs(params) {
  const res = await get('/permission/operation-logs', params)
  if (res.code !== 0) return res
  const d = res.data || {}
  return {
    code: 0,
    message: res.message,
    data: {
      list: (d.list || []).map((l) => ({
        id: l.id,
        admin: s(l.adminName || l.username),
        module: s(l.module),
        action: s(l.action),
        detail: s(l.detail),
        ip: s(l.ip),
        time: s(l.createdAt)
      })),
      total: n(d.total)
    }
  }
}

/** 站点配置（后端 key-value 列表 → 扁平对象） */
export async function getSiteConfig() {
  const res = await get('/system/configs')
  if (res.code !== 0) return res
  const cfg = {}
  for (const c of res.data || []) {
    cfg[c.configKey || c.key] = c.configValue ?? c.value
  }
  return { code: 0, message: 'ok', data: cfg }
}

export function saveSiteConfig(payload) {
  // 逐 key 保存（后端为单 key 更新接口）
  return (async () => {
    for (const [key, value] of Object.entries(payload)) {
      const r = await put(`/system/configs/${key}`, { config_value: String(value) }, { silent: true })
      if (r.code !== 0) return r
    }
    return { code: 0, message: '保存成功', data: null }
  })()
}

// ---- 平台清库（四步流：预览 → 输入确认文本 → 密码 → 短信验证码执行） ----

export function getCleanupPreview() {
  return get('/platform/cleanup-preview')
}

export function sendCleanupCode(phone) {
  return post('/platform/cleanup-send-code', { phone })
}

export function executeCleanup({ code, reason = '' }) {
  return post('/platform/cleanup-execute', { code, reason })
}

export function getCleanupLogs(params) {
  return get('/platform/cleanup-logs', params)
}

/** 兼容旧视图入口（一步式调用 → 内部走新四步流由页面驱动） */
export function cleanupPlatform({ confirmText }) {
  return Promise.resolve({ code: 4220, message: '请通过平台清库标准流程执行', data: null })
}
