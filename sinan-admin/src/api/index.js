import { mock, queryList, nextId } from '@/utils/request'
import * as db from '@/mock/db'

// ---------------- 认证 ----------------
export function login({ username, password }) {
  return mock(() => {
    const admin = db.admins.find((a) => a.username === username && a.status === 'enabled')
    if (!admin || password !== 'admin123' && admin.role !== 'super') {
      throw new Error('账号或密码错误（演示账号 admin / admin123）')
    }
    return {
      token: 'mock-token-' + Date.now(),
      admin: { id: admin.id, username: admin.username, name: admin.name, role: admin.role, avatar: admin.avatar }
    }
  }, 500)
}

export function logout() {
  return mock(() => true, 200)
}

// ---------------- 看板 ----------------
export const getDashboard = () => mock(() => db.dashboard, 300)

// ---------------- 用户 ----------------
export const getUserList = (params) =>
  mock(() => queryList(db.users, { fields: ['nickname', 'phone'], ...params }))

export const getUserDetail = (id) =>
  mock(() => {
    const u = db.users.find((i) => i.id === id)
    if (!u) throw new Error('用户不存在')
    u.orders = db.orders.filter((o) => o.userId === id).slice(0, 3)
    u.transfers = db.walletTransactions.filter((t) => t.userId === id).slice(0, 5)
    return u
  })

export function freezeUser(id) {
  return mockWrite(() => {
    const u = db.users.find((i) => i.id === id)
    u.status = u.status === 'normal' ? 'frozen' : 'normal'
    return u.status
  })
}

export function resetTradePwd(id) {
  return mockWrite(() => true)
}

export function auditRealname(id, pass) {
  return mockWrite(() => {
    const u = db.users.find((i) => i.id === id)
    u.realnameStatus = pass ? 'approved' : 'rejected'
    return true
  })
}

// ---------------- 藏品 ----------------
export const getCollectibleList = (params) =>
  mock(() => queryList(db.collectibles, { fields: ['name', 'subtitle'], ...params }))

export const getCollectibleDetail = (id) =>
  mock(() => {
    const c = db.collectibles.find((i) => i.id === id)
    if (!c) throw new Error('藏品不存在')
    const quotas = db.quotas.filter((q) => q.collectibleId === id)
    const holders = db.users.slice(0, 5).map((u, i) => ({
      nickname: u.nickname,
      quantity: [3, 1, 2, 1, 5][i],
      serial: `SN-${id}-00${10 + i}`
    }))
    // 守恒审计
    const pool = c.edition - c.sold - c.lockedQuantity - c.reservedCount - c.airdroppedCount - c.destroyedCount
    const sum = pool + c.lockedQuantity + c.reservedCount + c.sold + c.airdroppedCount + c.destroyedCount
    return { ...c, quotas, holders, audit: { ok: sum === c.edition, pool, sum, edition: c.edition } }
  })

export function saveCollectible(payload) {
  return mockWrite(() => {
    if (payload.id) {
      const idx = db.collectibles.findIndex((i) => i.id === payload.id)
      if (idx > -1) db.collectibles[idx] = { ...db.collectibles[idx], ...payload }
      return payload.id
    }
    const id = nextId(db.collectibles)
    db.collectibles.unshift({
      id, cover: '/images/collections/cover-collection-6.jpg', circulate: 0, sold: 0,
      lockedQuantity: 0, reservedCount: 0, airdroppedCount: 0, destroyedCount: 0,
      isBlindBox: false, status: 'upcoming', featured: false, ...payload
    })
    return id
  })
}

export function toggleCollectibleStatus(id, action) {
  return mockWrite(() => {
    const c = db.collectibles.find((i) => i.id === id)
    if (action === 'forceSoldout') { c.status = 'soldout'; return 'soldout' }
    if (action === 'offline') { c.status = 'offline'; return 'offline' }
    if (action === 'online') { c.status = 'onsale'; return 'onsale' }
    return c.status
  })
}

export function airdropCollectible({ id, phones, quantity }) {
  return mockWrite(() => {
    const c = db.collectibles.find((i) => i.id === id)
    const total = quantity * phones.length
    c.airdroppedCount += total
    c.circulate += total
    return { users: phones.length, perUser: quantity, total }
  })
}

export function destroyCollectible({ id, quantity }) {
  return mockWrite(() => {
    const c = db.collectibles.find((i) => i.id === id)
    c.destroyedCount += quantity
    return { destroyed: quantity }
  })
}

export function addQuota({ collectibleId, quotaType, quotaName, quantity }) {
  return mockWrite(() => {
    const c = db.collectibles.find((i) => i.id === collectibleId)
    c.reservedCount += quantity
    db.quotas.push({
      id: nextId(db.quotas), collectibleId, quotaType, quotaName,
      plannedQuantity: quantity, usedQuantity: 0, status: 1, activityType: 'other', remark: ''
    })
    return true
  })
}

export function toggleQuota(quotaId) {
  return mockWrite(() => {
    const q = db.quotas.find((i) => i.id === quotaId)
    q.status = q.status === 1 ? 0 : 1
    return q.status
  })
}

// ---------------- 盲盒 ----------------
export const getBlindBoxList = (params) =>
  mock(() => queryList(db.blindBoxes, { fields: ['name'], ...params }))

export const getBlindBoxDetail = (id) =>
  mock(() => {
    const b = db.blindBoxes.find((i) => i.id === id)
    if (!b) throw new Error('盲盒不存在')
    b.probabilitySum = b.items.reduce((s, i) => s + i.probability, 0)
    return b
  })

export function toggleBlindBoxOpenable(id) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === id)
    b.isOpenable = b.isOpenable === 1 ? 0 : 1
    return b.isOpenable
  })
}

export function saveBlindBoxPrize({ boxId, prizeCollectibleId, probability, quantityLimit }) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === boxId)
    const prize = b.items.find((i) => i.prizeCollectibleId === prizeCollectibleId)
    const c = db.collectibles.find((i) => i.id === prizeCollectibleId)
    if (prize) {
      prize.probability = probability
      if (quantityLimit !== undefined) prize.quantityLimit = quantityLimit
    } else {
      b.items.push({
        id: nextId(b.items), prizeCollectibleId, prizeName: c.name,
        cover: c.cover, probability, quantityLimit: quantityLimit ?? null, quantityDistributed: 0
      })
    }
    b.probabilitySum = b.items.reduce((s, i) => s + i.probability, 0)
    return true
  })
}

// ---------------- 订单 / 退款 ----------------
export const getOrderList = (params) =>
  mock(() => queryList(db.orders, { fields: ['orderNo', 'userName', 'userPhone', 'collectibleName'], ...params }))

export function orderAction(id, action) {
  return mockWrite(() => {
    const o = db.orders.find((i) => i.id === id)
    if (action === 'markPaid') { o.status = 'paid'; o.payTime = db.helpers.dt(Date.now()) }
    if (action === 'cancel') o.status = 'cancelled'
    if (action === 'complete') o.status = 'completed'
    if (action === 'applyRefund') o.status = 'refunding'
    return o.status
  })
}

export const getRefundList = (params) =>
  mock(() => queryList(db.refunds, { fields: ['orderNo', 'userName', 'collectibleName'], ...params }))

export function refundAction(id, action) {
  return mockWrite(() => {
    const r = db.refunds.find((i) => i.id === id)
    r.status = action === 'approve' ? 'approved' : 'rejected'
    r.handleTime = db.helpers.dt(Date.now())
    if (action === 'approve') {
      const o = db.orders.find((x) => x.orderNo === r.orderNo)
      if (o) o.status = 'refunded'
    }
    return r.status
  })
}

// ---------------- 寄售 / 转赠 ----------------
export const getResaleList = (params) =>
  mock(() => queryList(db.resaleListings, { fields: ['listingNo', 'sellerName', 'collectibleName'], ...params }))

export function resaleAction(id, action) {
  return mockWrite(() => {
    const r = db.resaleListings.find((i) => i.id === id)
    r.status = action === 'freeze' ? 'frozen' : action === 'unfreeze' ? 'onsale' : 'cancelled'
    return r.status
  })
}

export const getTransferList = (params) =>
  mock(() => queryList(db.transfers, { fields: ['fromUser', 'toUser', 'collectibleName'], ...params }))

export function transferAction(id, action) {
  return mockWrite(() => {
    const t = db.transfers.find((i) => i.id === id)
    t.status = action === 'approve' ? 'completed' : 'rejected'
    return t.status
  })
}

// ---------------- 营销 ----------------
export const getCheckinConfig = () => mock(() => db.checkinConfig, 200)
export function saveCheckinRules(payload) {
  return mockWrite(() => { Object.assign(db.checkinConfig, payload); return true })
}
export function toggleCheckin() {
  return mockWrite(() => { db.checkinConfig.enabled = db.checkinConfig.enabled === 1 ? 0 : 1; return db.checkinConfig.enabled })
}

export const getLuckyDraws = () => mock(() => db.luckyDraws, 260)
export function toggleLuckyDraw(id) {
  return mockWrite(() => {
    const a = db.luckyDraws.find((i) => i.id === id)
    a.status = a.status === 'enabled' ? 'disabled' : 'enabled'
    return a.status
  })
}
export function saveLuckyDrawPrize({ activityId, prizeId, probability, total }) {
  return mockWrite(() => {
    const a = db.luckyDraws.find((i) => i.id === activityId)
    const p = a.prizes.find((i) => i.id === prizeId)
    if (probability !== undefined) p.probability = probability
    if (total !== undefined) p.total = total
    return true
  })
}

export const getSynthesisList = () => mock(() => db.synthesisActivities, 260)
export function toggleSynthesis(id) {
  return mockWrite(() => {
    const a = db.synthesisActivities.find((i) => i.id === id)
    a.status = a.status === 'enabled' ? 'disabled' : 'enabled'
    return a.status
  })
}
export function saveSynthesis(payload) {
  return mockWrite(() => { Object.assign(db.synthesisActivities.find((i) => i.id === payload.id), payload); return true })
}

export const getInviteActivity = () => mock(() => db.inviteActivity, 260)
export function saveInviteActivity(payload) {
  return mockWrite(() => { Object.assign(db.inviteActivity, payload); return true })
}

export const getPrioritySales = () => mock(() => db.prioritySales, 260)
export function addWhitelist({ saleId, phone, quantity, expiresAt }) {
  return mockWrite(() => {
    const s = db.prioritySales.find((i) => i.id === saleId)
    const u = db.users.find((x) => x.phone === phone) || { nickname: phone, id: 9999 }
    s.whitelists.push({ id: nextId(s.whitelists), userId: u.id, nickname: u.nickname, phone, maxQuantity: quantity, usedQuantity: 0, expiresAt })
    s.whitelistCount += 1
    return true
  })
}

// ---------------- 钱包 ----------------
export const getWalletTransactions = (params) =>
  mock(() => queryList(db.walletTransactions, { fields: ['userName', 'userPhone', 'title'], ...params }))

export const getWalletStats = () => mock(() => ({
  todayRecharge: 1800, todayConsume: 1691, todayReward: 175, monthRecharge: 52600
}), 200)

// ---------------- 内容 ----------------
export const getAnnouncements = (params) =>
  mock(() => queryList(db.announcements, { fields: ['title'], ...params }))

export function saveAnnouncement(payload) {
  return mockWrite(() => {
    if (payload.id) {
      Object.assign(db.announcements.find((i) => i.id === payload.id), payload)
    } else {
      db.announcements.unshift({ id: nextId(db.announcements), views: 0, publishTime: null, ...payload })
    }
    return true
  })
}

export function removeAnnouncement(id) {
  return mockWrite(() => {
    const idx = db.announcements.findIndex((i) => i.id === id)
    if (idx > -1) db.announcements.splice(idx, 1)
    return true
  })
}

export const getBanners = () => mock(() => db.banners, 200)

export function saveBanner(payload) {
  return mockWrite(() => {
    if (payload.id) Object.assign(db.banners.find((i) => i.id === payload.id), payload)
    else db.banners.push({ id: nextId(db.banners), ...payload })
    return true
  })
}

export const getCommunityGroups = () => mock(() => db.communityGroups, 200)

export function saveCommunityGroup(payload) {
  return mockWrite(() => {
    if (payload.id) Object.assign(db.communityGroups.find((i) => i.id === payload.id), payload)
    else db.communityGroups.push({ id: nextId(db.communityGroups), ...payload })
    return true
  })
}

export const getArtifacts = (params) =>
  mock(() => queryList(db.artifacts, { fields: ['name', 'museum'], ...params }))

export function saveArtifact(payload) {
  return mockWrite(() => {
    if (payload.id) Object.assign(db.artifacts.find((i) => i.id === payload.id), payload)
    else db.artifacts.push({ id: nextId(db.artifacts), image: '/images/exhibits/exhibit-5.jpg', ...payload })
    return true
  })
}

// ---------------- 系统 ----------------
export const getAdmins = () => mock(() => db.admins, 200)
export const getRoles = () => mock(() => ({ roles: db.roles, tree: db.permissionTree }), 200)
export const getLoginLogs = (params) => mock(() => queryList(db.loginLogs, { fields: ['username', 'ip'], ...params }))
export const getOperationLogs = (params) => mock(() => queryList(db.operationLogs, { fields: ['admin', 'module', 'action', 'detail'], ...params }))
export const getSiteConfig = () => mock(() => db.siteConfig, 200)
export function saveSiteConfig(payload) {
  return mockWrite(() => { Object.assign(db.siteConfig, payload); return true })
}
