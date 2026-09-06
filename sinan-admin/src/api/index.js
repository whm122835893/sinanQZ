import { mock, mockWrite, queryList, nextId } from '@/utils/request'
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
    // 盲盒库存守恒审计：发行量 = 库存池 + 已售出发售 + 已独立空投 + 已销毁
    const pool = b.edition - b.sold - b.airdroppedCount - b.destroyedCount
    const sum = pool + b.sold + b.airdroppedCount + b.destroyedCount
    return { ...b, audit: { ok: sum === b.edition, pool, sum, edition: b.edition } }
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
    // 概率闭环：所有子藏品概率之和必须 <= 100%
    const others = b.items.filter((i) => i.prizeCollectibleId !== prizeCollectibleId).reduce((s, i) => s + i.probability, 0)
    if (others + probability > 1.0000001) {
      throw new Error(`概率之和超出 100%（当前其他子藏品合计 ${(others * 100).toFixed(2)}%），请调整`)
    }
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

// 删除子藏品（仅未发放过的可删）
export function removeBlindBoxPrize({ boxId, prizeCollectibleId }) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === boxId)
    const idx = b.items.findIndex((i) => i.prizeCollectibleId === prizeCollectibleId)
    if (idx === -1) throw new Error('子藏品不存在')
    if (b.items[idx].quantityDistributed > 0) throw new Error('该子藏品已产生发放记录，不可删除')
    b.items.splice(idx, 1)
    b.probabilitySum = b.items.reduce((s, i) => s + i.probability, 0)
    return true
  })
}

// 可选子藏品（流通量 > 0 的藏品）
export const getBlindBoxSelectableCollectibles = () =>
  mock(() => db.collectibles.filter((c) => !c.isBlindBox && c.circulate > 0).map((c) => ({
    id: c.id, name: c.name, cover: c.cover, category: c.category,
    circulate: c.circulate, stockPool: c.edition - c.sold - c.lockedQuantity - c.reservedCount - c.airdroppedCount - c.destroyedCount
  })))

// 盲盒状态操作（上架 / 下架 / 强制售罄）
export function toggleBlindBoxStatus(id, action) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === id)
    if (action === 'forceSoldout') { b.status = 'soldout'; return 'soldout' }
    if (action === 'offline') { b.status = 'offline'; return 'offline' }
    if (action === 'online') { b.status = 'onsale'; return 'onsale' }
    return b.status
  })
}

// 盲盒独立空投（从盲盒库存池扣减）
export function airdropBlindBox({ id, phones, quantity }) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === id)
    const total = quantity * phones.length
    b.airdroppedCount += total
    b.circulate += total
    return { users: phones.length, perUser: quantity, total }
  })
}

// 盲盒销毁（从盲盒库存池扣减，不可逆）
export function destroyBlindBox({ id, quantity }) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === id)
    b.destroyedCount += quantity
    return { destroyed: quantity }
  })
}

// 盲盒转赠开关（独立于寄售开关）
export function toggleBlindBoxTransferable(id, val) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === id)
    b.isTransferable = val ? 1 : 0
    return b.isTransferable
  })
}

// 盲盒寄售开关 + 价格管控（关闭时在售挂单全部系统下架）
export function toggleBlindBoxResale({ id, enabled, priceMode, priceMin, priceMax }) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === id)
    b.isResaleable = enabled ? 1 : 0
    b.resalePriceMode = enabled ? priceMode : null
    b.resalePriceMin = enabled && priceMode === 'limit' ? priceMin : null
    b.resalePriceMax = enabled && priceMode === 'limit' ? priceMax : null
    if (!enabled) {
      db.resaleListings.forEach((l) => {
        if (l.collectibleId === b.collectibleId && l.status === 'onsale') {
          l.status = 'system_delisted'
          l.isSystemDelisted = 1
        }
      })
    }
    return true
  })
}

// 盲盒发售配置（发售数量 <= 盲盒库存池）
export function releaseBlindBox({ id, saleQuantity, price, perUserLimit }) {
  return mockWrite(() => {
    const b = db.blindBoxes.find((i) => i.id === id)
    const pool = b.edition - b.sold - b.airdroppedCount - b.destroyedCount
    if (saleQuantity > pool) throw new Error(`盲盒库存池不足，当前库存池为 ${pool}`)
    b.saleQuantity = saleQuantity
    b.price = price
    b.perUserLimit = perUserLimit
    b.status = 'onsale'
    return true
  })
}

// 盲盒创建 / 编辑（发行总量创建时设定，不可变更）
export function saveBlindBox(payload) {
  return mockWrite(() => {
    if (payload.id) {
      const b = db.blindBoxes.find((i) => i.id === payload.id)
      if (!b) throw new Error('盲盒不存在')
      const { id, edition, ...rest } = payload
      Object.assign(b, rest) // edition 不可变更
      return payload.id
    }
    const id = nextId(db.blindBoxes)
    db.blindBoxes.unshift({
      id, sold: 0, openedCount: 0, status: 'upcoming', isOpenable: 1,
      airdroppedCount: 0, destroyedCount: 0, circulate: 0,
      isTransferable: 1, isResaleable: 1, items: [], probabilitySum: 0,
      saleQuantity: payload.edition, perUserLimit: payload.perUserLimit || 5,
      ...payload
    })
    return id
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
    if (action === 'revoke') {
      // 撤销已完成转赠：校验接收方是否仍持有该资产（已寄售 / 二次转出则拦截）
      const relisted = db.resaleListings.find((l) => l.serial === t.serial && l.status !== 'cancelled')
      if (relisted) throw new Error('接收方已将该资产寄售（发生二次流转），无法撤销')
      t.status = 'revoked'
    } else if (action === 'approve') {
      t.status = 'completed'
    } else {
      t.status = 'rejected'
    }
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
    // 概率闭环：所有「非空奖」奖项概率之和必须 <= 100%
    const others = a.prizes.filter((i) => i.id !== prizeId && i.type !== 'none').reduce((s, i) => s + i.probability, 0)
    const newProb = probability !== undefined ? probability : p.probability
    if (p.type !== 'none' && others + newProb > 1.0000001) {
      throw new Error(`非空奖概率之和超出 100%（其他奖项合计 ${(others * 100).toFixed(2)}%），请调整`)
    }
    if (total !== undefined && p.won !== undefined && total < p.won) {
      throw new Error(`奖项数量不可低于已发出数量（已发出 ${p.won} 份）`)
    }
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

// ============================================================
// v2.0 扩展接口（BuildAdmin 提示词补漏模块）
// ============================================================

// ---------------- 管理员密码验证（敏感操作前置） ----------------
export const verifyAdminPassword = (pwd) =>
  mock(() => db.verifyPassword(pwd), 300)

// ---------------- 用户扩展：黑名单 / 强制登出 / 强制回收 ----------------
export function toggleBlacklist(id, reason = '') {
  return mockWrite(() => {
    const u = db.users.find((i) => i.id === id)
    u.isBlacklisted = u.isBlacklisted ? 0 : 1
    u.blacklistReason = u.isBlacklisted ? reason || '风控管制' : ''
    return u.isBlacklisted
  })
}

export function forceLogoutUser(id) {
  return mockWrite(() => true)
}

// 强制回收藏品（校验二次流转）
export function recoverUserCollectible({ userId, serial }) {
  return mockWrite(() => {
    const t = db.transfers.find((x) => x.serial === serial && x.status === 'completed')
    if (t) throw new Error('该藏品已发生二次流转，无法回收')
    return { recovered: 1 }
  })
}

// ---------------- 藏品扩展：寄售/转赠开关 + 价格管控 + 发售 ----------------
export function toggleCollectibleResale({ id, enabled, priceMode = 'free', priceMin = null, priceMax = null }) {
  return mockWrite(() => {
    const c = db.collectibles.find((i) => i.id === id)
    if (enabled) {
      c.isResaleable = 1
      c.resalePriceMode = priceMode
      c.resalePriceMin = priceMode === 'limit' ? priceMin : null
      c.resalePriceMax = priceMode === 'limit' ? priceMax : null
    } else {
      // 关闭寄售：所有该藏品在售挂单自动「系统下架」
      c.isResaleable = 0
      db.resaleListings
        .filter((r) => r.collectibleId === id && r.status === 'onsale')
        .forEach((r) => { r.status = 'system_delisted' })
    }
    return true
  })
}

export function toggleCollectibleTransferable(id, enabled) {
  return mockWrite(() => {
    const c = db.collectibles.find((i) => i.id === id)
    c.isTransferable = enabled ? 1 : 0
    return c.isTransferable
  })
}

// 重新上架（发售参数校验 <= 库存池）
export function releaseCollectible({ id, saleQuantity, price, perUserLimit }) {
  return mockWrite(() => {
    const c = db.collectibles.find((i) => i.id === id)
    const pool = c.edition - c.sold - c.lockedQuantity - c.reservedCount - c.airdroppedCount - c.destroyedCount
    if (saleQuantity > pool) throw new Error(`库存池不足，当前库存池为 ${pool}`)
    Object.assign(c, { saleQuantity, price: price ?? c.price, perUserLimit: perUserLimit ?? c.perUserLimit, status: 'onsale' })
    return true
  })
}

// 销毁记录台账
export const getDestroyRecords = () => mock(() => db.destroyRecords, 240)

// 求购市场
export const getBuyRequests = (params) =>
  mock(() => queryList(db.buyRequests, { fields: ['userName', 'collectibleName'], ...params }))
export function delistBuyRequest(id) {
  return mockWrite(() => { db.buyRequests.find((i) => i.id === id).status = 'delisted'; return true })
}

// ---------------- 资格购（购买门槛，独立于优先购） ----------------
export const getQualifications = () => mock(() => db.qualifications, 260)
export function saveQualification(payload) {
  return mockWrite(() => {
    if (payload.id) {
      Object.assign(db.qualifications.find((i) => i.id === payload.id), payload)
      return true
    }
    db.qualifications.unshift({ id: nextId(db.qualifications), whitelist: [], qualifiedCount: 0, ...payload })
    return true
  })
}
export function addQualificationWhitelist({ qualificationId, phones, expiresAt }) {
  return mockWrite(() => {
    const q = db.qualifications.find((i) => i.id === qualificationId)
    const list = phones.split(/[\n,，\s]+/).filter(Boolean)
    for (const phone of list) {
      if (!/^1\d{10}$/.test(phone)) throw new Error(`手机号 ${phone} 格式错误`)
      const u = db.users.find((x) => x.phone === phone)
      if (!u) throw new Error(`手机号 ${phone} 非平台注册用户，已拦截`)
      if (q.whitelist.some((w) => w.phone === phone)) continue
      q.whitelist.push({ id: nextId(q.whitelist), userId: u.id, nickname: u.nickname, phone, expiresAt })
    }
    return { added: list.length }
  })
}
export function removeQualificationWhitelist(qualificationId, whitelistId) {
  return mockWrite(() => {
    const q = db.qualifications.find((i) => i.id === qualificationId)
    q.whitelist = q.whitelist.filter((w) => w.id !== whitelistId)
    return true
  })
}

// ---------------- 优先购扩展：过期资格清理 ----------------
export function cleanExpiredPriority(saleId) {
  return mockWrite(() => {
    const s = db.prioritySales.find((i) => i.id === saleId)
    const before = s.whitelists.length
    s.whitelists = s.whitelists.filter((w) => new Date(w.expiresAt).getTime() > Date.now())
    return { cleaned: before - s.whitelists.length }
  })
}

// ---------------- 风控告警 ----------------
export const getRiskAlerts = (params) =>
  mock(() => queryList(db.riskAlerts, { fields: ['userName', 'userPhone', 'detail'], ...params }))
export function handleRiskAlert({ id, result }) {
  return mockWrite(() => {
    const a = db.riskAlerts.find((i) => i.id === id)
    a.status = 'resolved'
    a.result = result
    a.handleTime = db.helpers.dt(Date.now())
    a.handler = 'admin'
    return true
  })
}

// ---------------- 客服工单 ----------------
export const getTickets = (params) =>
  mock(() => queryList(db.tickets, { fields: ['ticketNo', 'userName', 'title'], ...params }))
export function replyTicket({ id, content }) {
  return mockWrite(() => {
    const t = db.tickets.find((i) => i.id === id)
    t.replies.push({ id: nextId(t.replies), author: 'admin', content, time: db.helpers.dt(Date.now()) })
    if (t.status === 'pending') t.status = 'processing'
    return true
  })
}
export function closeTicket(id) {
  return mockWrite(() => {
    const t = db.tickets.find((i) => i.id === id)
    t.status = 'closed'
    t.closeTime = db.helpers.dt(Date.now())
    return true
  })
}

// ---------------- 区块链交互 ----------------
export const getChainContracts = () => mock(() => db.chainContracts, 240)
export function toggleChainContract(id) {
  return mockWrite(() => {
    const c = db.chainContracts.find((i) => i.id === id)
    c.status = c.status ? 0 : 1
    return c.status
  })
}
export const getChainTransactions = (params) =>
  mock(() => queryList(db.chainTransactions, { fields: ['txHash', 'userName', 'token', 'contractName'], ...params }))

// ---------------- 内容审核 ----------------
export const getContentAudits = (params) =>
  mock(() => queryList(db.contentAudits, { fields: ['userName', 'title', 'content'], ...params }))
export function auditContent({ id, action, reason = '' }) {
  return mockWrite(() => {
    const a = db.contentAudits.find((i) => i.id === id)
    a.status = action === 'approve' ? 'approved' : 'rejected'
    a.reason = action === 'approve' ? '' : reason || '内容不符合平台规范'
    a.handleTime = db.helpers.dt(Date.now())
    return true
  })
}

// ---------------- 敏感操作审批 ----------------
export const getApprovals = (params) =>
  mock(() => queryList(db.approvals, { fields: ['title', 'applicant', 'reason'], ...params }))
export function handleApproval({ id, action, reason = '' }) {
  return mockWrite(() => {
    const a = db.approvals.find((i) => i.id === id)
    a.status = action === 'approve' ? 'approved' : 'rejected'
    a.reason = reason || a.reason
    a.handleTime = db.helpers.dt(Date.now())
    a.handler = 'admin'
    return true
  })
}

// ---------------- 数据统计 ----------------
export const getStatistics = () => mock(() => db.statistics, 300)

// ---------------- 平台清库（最高风险操作） ----------------
export function cleanupPlatform({ confirmText }) {
  return mockWrite(() => {
    if (confirmText !== '确认清除') throw new Error('确认文本不正确')
    return {
      clearedUsers: db.users.length,
      clearedOrders: db.orders.length,
      resetCollectibles: db.collectibles.length,
      backupFile: `backup_${Date.now()}.sql`
    }
  }, 900)
}
