import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

// 用户状态：token / userInfo / 登录态
export const useUserStore = defineStore('user', () => {
  const token = ref(localStorage.getItem('jc_token') || '')
  const userInfo = ref({
    nickname: 'OIgsRAJPTobXv8',
    avatar: '',
    phone: '175****1293',
    walletAddress: '0x9e36****3f36',
    isRealName: false
  })

  const isLoggedIn = computed(() => !!token.value)

  // 我的藏品库存（mock：默认含部分藏品，购买成功后继续入库）
  const inventory = ref([
    { id: '1', name: '龙纹罗盘', coverImage: '/images/collections/cover-1.jpg', price: '0.10', qty: 3, no: 'SN-1-0001', type: 'release', boughtAt: Date.now() },
    { id: '2', name: '虎纹卡牌', coverImage: '/images/collections/cover-2.jpg', price: '0.10', qty: 2, no: 'SN-2-0001', type: 'release', boughtAt: Date.now() },
    { id: '3', name: '水晶菱形', coverImage: '/images/collections/cover-3.jpg', price: '0.10', qty: 1, no: 'SN-3-0001', type: 'release', boughtAt: Date.now() }
  ])
  // 我的寄售挂单（mock：从仓库发起寄售后记录）
  const consignments = ref([])
  // 交易密码（mock：统一为 123456）
  const payPassword = ref('123456')

  function setUserInfo(info) {
    userInfo.value = { ...userInfo.value, ...info }
  }

  function login(payload) {
    // mock 登录：写入 token 与基础用户信息
    token.value = 'mock_token_' + Date.now()
    localStorage.setItem('jc_token', token.value)
    if (payload?.phone) {
      const p = String(payload.phone)
      userInfo.value.phone = p.slice(0, 3) + '****' + p.slice(-4)
    }
    return Promise.resolve()
  }

  function logout() {
    token.value = ''
    localStorage.removeItem('jc_token')
  }

  function fetchUserInfo() {
    // mock：保持默认占位信息
    return Promise.resolve(userInfo.value)
  }

  // 校验交易密码
  function verifyPaymentPassword(pwd) {
    return String(pwd) === payPassword.value
  }

  // 已购数量（用于每人限购判断）
  function ownedCount(id) {
    return inventory.value
      .filter(i => String(i.id) === String(id))
      .reduce((sum, i) => sum + (i.qty || 1), 0)
  }

  // 购买成功：藏品入库
  function addToInventory(item) {
    inventory.value.push({
      id: item.id,
      name: item.name,
      coverImage: item.coverImage,
      price: item.price,
      qty: item.qty || 1,
      no: item.no || '',
      type: item.type || 'release',
      boughtAt: Date.now()
    })
    return Promise.resolve()
  }

  // 合成消耗材料：库存中对应的藏品数量 -1（不足则忽略）
  function consume(id, qty = 1) {
    const idx = inventory.value.findIndex((i) => String(i.id) === String(id))
    if (idx !== -1) {
      if (inventory.value[idx].qty > qty) {
        inventory.value[idx].qty -= qty
      } else {
        inventory.value.splice(idx, 1)
      }
    }
    return Promise.resolve()
  }

  // 发起寄售：库存减少对应数量，写入寄售挂单
  function consign(payload) {
    const idx = inventory.value.findIndex(i => String(i.id) === String(payload.id))
    if (idx !== -1) {
      if (inventory.value[idx].qty > 1) {
        inventory.value[idx].qty -= 1
      } else {
        inventory.value.splice(idx, 1)
      }
    }
    consignments.value.unshift({
      id: payload.id,
      name: payload.name,
      coverImage: payload.coverImage,
      no: payload.no || '',
      price: payload.price,
      fee: payload.fee,
      actual: payload.actual,
      status: 'onsale', // onsale 寄售中 / sold 已售出
      createdAt: Date.now()
    })
    return Promise.resolve()
  }

  return {
    token, userInfo, isLoggedIn, inventory, payPassword, consignments,
    setUserInfo, login, logout, fetchUserInfo,
    verifyPaymentPassword, ownedCount, addToInventory, consume, consign
  }
})
