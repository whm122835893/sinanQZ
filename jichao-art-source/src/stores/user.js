import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import request from '@/utils/request'

// 用户状态：token / userInfo / 登录态
// MOCK_REPLACED: 原数据来自本文件内联 mock（inventory/consignments/payPassword/login 本地 token），
// 现已接入真实接口：/api/auth/*、/api/user/profile、/api/user/collections、
// /api/resale/listings(/mine)、/api/blind-boxes/open、/api/check-in、/api/user/verify-trade-password
export const useUserStore = defineStore('user', () => {
  const token = ref(localStorage.getItem('jc_token') || '')
  const userInfo = ref({
    nickname: '',
    avatar: '',
    phone: '',
    walletAddress: '',
    isRealName: false,
    inviteCode: ''
  })

  const isLoggedIn = computed(() => !!token.value)

  function setToken(t) {
    token.value = t
    if (t) localStorage.setItem('jc_token', t)
    else localStorage.removeItem('jc_token')
  }

  function setUserInfo(info) {
    userInfo.value = { ...userInfo.value, ...info }
  }

  // ---- 登录/注册（真实接口：POST /api/auth/login，验证码模式）----
  async function login(payload) {
    const res = await request.post('/auth/login', {
      phone: payload.phone,
      code: payload.code
    })
    setToken(res.token)
    setUserInfo({
      nickname: res.userInfo.username,
      avatar: res.userInfo.avatar || '',
      phone: res.userInfo.phone,
      isRealName: !!res.userInfo.isRealname,
      inviteCode: res.userInfo.inviteCode
    })
    return res
  }

  // ---- 发送验证码（真实接口：POST /api/auth/send-code；开发环境返回 debugCode）----
  async function sendCode(phone, scene = 'login') {
    const res = await request.post('/auth/send-code', { phone, scene })
    return res // { debugCode?: '123456' }
  }

  // ---- 注册（真实接口：POST /api/auth/register，验证码模式，支持邀请码）----
  // 注册成功后端直接返回 token（注册即登录）
  async function register(payload) {
    const res = await request.post('/auth/register', {
      phone: payload.phone,
      code: payload.code,
      nickname: payload.nickname,
      inviteCode: payload.inviteCode || ''
    })
    setToken(res.token)
    setUserInfo({
      nickname: res.userInfo.username,
      avatar: res.userInfo.avatar || '',
      phone: res.userInfo.phone,
      isRealName: !!res.userInfo.isRealname,
      inviteCode: res.userInfo.inviteCode
    })
    return res
  }

  function logout() {
    setToken('')
  }

  // ---- 用户信息（真实接口：GET /api/user/profile）----
  async function fetchUserInfo() {
    if (!token.value) return userInfo.value
    const u = await request.get('/user/profile')
    setUserInfo({
      nickname: u.nickname || u.username || userInfo.value.nickname,
      avatar: u.avatar || '',
      phone: u.phone,
      isRealName: !!u.isRealName,
      inviteCode: u.inviteCode,
      wallet: u.wallet
    })
    return userInfo.value
  }

  // ---- 交易密码（真实接口：POST /api/user/verify-trade-password）----
  // MOCK_REPLACED: 原为本地常量 '123456' 比对，现走后端校验
  async function verifyPaymentPassword(pwd) {
    try {
      await request.post('/user/verify-trade-password', { password: String(pwd) })
      return true
    } catch {
      return false
    }
  }

  // ---- 我的藏品库存（真实接口：GET /api/user/collections，按藏品聚合）----
  const inventory = ref([])
  async function fetchInventory() {
    if (!token.value) return []
    const list = await request.get('/user/collections')
    inventory.value = (list || []).map((g) => ({
      id: String(g.id),
      name: g.name,
      coverImage: g.image,
      price: Number(g.price).toFixed(2),
      qty: g.qty,
      nos: g.nos || [],
      lockedNos: g.isConsigned ? (g.nos || []).slice(0, 1) : [],
      type: g.type === 'blindbox' ? 'blindbox' : 'release',
      boughtAt: g.acquiredAt ? new Date(String(g.acquiredAt).replace(/-/g, '/')).getTime() : 0,
      userCollectibleIds: g.userCollectibleIds || [],
      // 编号 → 资产实例明细（寄售/开盒按实例操作）
      items: (g.items || []).map((it) => ({
        userCollectibleId: it.userCollectibleId,
        serial: it.serial,
        isConsigned: !!it.isConsigned
      })),
      isConsigned: !!g.isConsigned
    }))
    return inventory.value
  }

  // 已购数量（用于每人限购判断）
  function ownedCount(id) {
    return inventory.value
      .filter((i) => String(i.id) === String(id))
      .reduce((sum, i) => sum + (i.qty || 1), 0)
  }

  // 按藏品编号查资产实例 ID（寄售/开盲盒需要 userCollectibleId）
  function findUserCollectibleId(collectibleId, serial) {
    const item = inventory.value.find((i) => String(i.id) === String(collectibleId))
    const hit = item?.items?.find((x) => x.serial === serial)
    return hit?.userCollectibleId || 0
  }

  // ---- 我的寄售挂单（真实接口：GET /api/resale/listings/mine）----
  const consignments = ref([])
  async function fetchConsignments() {
    if (!token.value) return []
    const res = await request.get('/resale/listings/mine')
    consignments.value = (res.list || []).map((l) => ({
      id: String(l.userCollectibleId),
      listingId: l.listingId,
      name: l.name,
      coverImage: l.image,
      no: l.no,
      price: Number(l.price).toFixed(2),
      fee: Number(l.feeAmount).toFixed(2),
      actual: Number(l.actualAmount).toFixed(2),
      status: l.status === 'selling' ? 'onsale' : l.status, // onsale 寄售中 / sold 已售出 / cancelled 已下架
      createdAt: l.listedAt
    }))
    return consignments.value
  }

  // ---- 发起寄售（真实接口：POST /api/resale/listings，需交易密码）----
  // MOCK_REPLACED: 原为本地锁定编号+内存挂单，现走后端（数据库状态机一致）
  async function consign(payload) {
    // payload: { userCollectibleId, price, paymentPassword }
    await request.post('/resale/listings', {
      userCollectibleId: payload.userCollectibleId,
      price: Number(payload.price),
      paymentPassword: String(payload.paymentPassword || '')
    })
    // 拉取最新库存与挂单（后端已将资产置为 consigned）
    await Promise.all([fetchInventory(), fetchConsignments()])
    return true
  }

  // ---- 取消寄售（真实接口：POST /api/resale/listings/:listingId/cancel）----
  async function cancelConsign(listingId) {
    await request.post(`/resale/listings/${listingId}/cancel`)
    await Promise.all([fetchInventory(), fetchConsignments()])
    return true
  }

  // 编号是否处于寄售锁定中（后端 isConsigned 状态）
  function isNoLocked(id, no) {
    const item = inventory.value.find((i) => String(i.id) === String(id))
    return !!(item && item.isConsigned && no && item.nos?.includes(no))
  }

  // 冷却期由后端校验（resale_cooldown_seconds），前端仅提示
  function consignCooldownRemain() {
    return 0
  }

  // ---- 开启盲盒（真实接口：POST /api/blind-boxes/open）----
  // MOCK_REPLACED: 原为本地 reveals 常量随机，现由后端 random_int 加权抽取
  async function openBlindbox(userCollectibleId, paymentPassword) {
    const res = await request.post('/blind-boxes/open', {
      userCollectibleId,
      paymentPassword: String(paymentPassword || '')
    })
    await fetchInventory()
    return res // { prize: { id, name, image, price } } 结构以实际返回为准
  }

  // ---- 每日签到（真实接口：POST /api/check-in；记录 GET /api/check-in/records）----
  const signState = ref({
    day: 0,
    lastSignDate: '',
    records: [] // [{ date: 'YYYY-MM-DD' }]
  })

  async function fetchSignCalendar() {
    if (!token.value) return signState.value
    const now = new Date()
    const p = (n) => String(n).padStart(2, '0')
    const res = await request.get('/check-in/records', {
      params: { month: `${now.getFullYear()}-${p(now.getMonth() + 1)}` }
    })
    signState.value.day = res.currentStreak || 0
    signState.value.records = (res.records || []).map((r) => ({ date: r.date }))
    signState.value.lastSignDate = signState.value.records[0]?.date || ''
    return signState.value
  }

  const todaySigned = computed(() => {
    const now = new Date()
    const p = (n) => String(n).padStart(2, '0')
    const today = `${now.getFullYear()}-${p(now.getMonth() + 1)}-${p(now.getDate())}`
    return signState.value.records.some((r) => r.date === today)
  })

  // 执行签到：成功返回 { ok: true, day }，今日已签返回 { already: true }
  async function doSign() {
    if (todaySigned.value) return { already: true }
    const res = await request.post('/check-in')
    await fetchSignCalendar()
    if (res.already) return { already: true }
    return { ok: true, day: res.day, reward: res.reward }
  }

  return {
    token, userInfo, isLoggedIn, inventory, consignments,
    signState, todaySigned,
    setUserInfo, login, sendCode, register, logout, fetchUserInfo,
    verifyPaymentPassword, ownedCount, fetchInventory, findUserCollectibleId,
    fetchConsignments, consign, cancelConsign, isNoLocked, consignCooldownRemain,
    openBlindbox, fetchSignCalendar, doSign
  }
})
