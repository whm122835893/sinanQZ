import { defineStore } from 'pinia'
import { ref } from 'vue'
import request from '@/utils/request'

// 订单状态：我的订单 / 申购订单
// MOCK_REPLACED: 原为本地内存订单（genOrderNo/addOrder/addPendingOrder 本地状态机 + 本地库存锁定），
// 现接入真实接口：POST /api/orders（创建，后端锁库存）、POST /api/orders/:orderNo/pay（支付）、
// POST /api/orders/:orderNo/cancel（取消）、GET /api/orders（列表）。
// 库存锁定/释放、限购校验、流通量更新均由后端事务保证，前端不再本地改库存。
export const useOrderStore = defineStore('order', () => {
  const orders = ref([])          // 我的订单
  const purchaseOrders = ref([])  // 转赠记录

  // 后端时间字符串（YYYY-MM-DD HH:mm:ss[.v]）→ 时间戳
  const toTs = (s) => (s ? new Date(String(s).replace(/-/g, '/')).getTime() : 0)

  // 创建订单（确认支付时调用；后端校验交易密码、锁库存并生成 5 分钟待支付订单）
  async function createOrder(payload) {
    const res = await request.post('/orders', {
      collectibleId: Number(payload.id),
      quantity: payload.qty || 1,
      resaleListingId: Number(payload.resaleListingId) || 0,
      no: payload.no || ''
    })
    const order = {
      id: res.orderNo,
      itemId: payload.id,
      cover: payload.coverImage,
      name: payload.name,
      no: payload.no || '',
      price: Number(payload.price),
      qty: payload.qty || 1,
      kind: res.source === 'market' ? 'resale' : 'release',
      status: 'pending',
      createdAt: Date.now(),
      expiresAt: toTs(res.expiresAt) || Date.now() + 5 * 60 * 1000
    }
    orders.value.unshift(order)
    return order
  }

  // 支付订单（交易密码由后端在 create/pay 两级校验）
  async function payOrder(orderNo, { paymentMethod = 'balance', paymentPassword = '' } = {}) {
    const res = await request.post(`/orders/${orderNo}/pay`, { paymentMethod, paymentPassword })
    const o = orders.value.find((x) => x.id === orderNo)
    if (o) o.status = 'done'
    return res
  }

  // 取消订单（幂等）：后端释放锁定库存 / 恢复挂单在售
  async function cancelOrder(orderNo) {
    await request.post(`/orders/${orderNo}/cancel`)
    const o = orders.value.find((x) => x.id === orderNo)
    if (o && o.status === 'pending') o.status = 'cancelled'
  }

  // 我的订单列表（后端状态 completed/cancelled → 前端 done/cancelled）
  async function fetchOrders() {
    const res = await request.get('/orders', { params: { page: 1, pageSize: 50 } })
    orders.value = (res.list || []).map((o) => ({
      id: o.orderNo,
      itemId: String(o.collectibleId),
      cover: o.image,
      name: o.name,
      no: '',
      price: o.price,
      qty: o.qty,
      kind: o.source === 'market' ? 'resale' : 'release',
      status: o.status === 'completed' ? 'done' : o.status,
      createdAt: toTs(o.createdAt),
      expiresAt: toTs(o.expiresAt)
    }))
    return orders.value
  }

  // 转赠记录（真实接口：GET /api/transfers/mine）
  async function fetchPurchaseOrders() {
    const res = await request.get('/transfers/mine', { params: { page: 1, pageSize: 50 } })
    purchaseOrders.value = (res.list || []).map((t) => ({
      id: String(t.transferId),
      name: t.name,
      cover: t.image,
      no: t.no,
      direction: t.direction,
      status: t.status,
      counterpart: t.counterpart,
      createdAt: toTs(t.createdAt)
    }))
    return purchaseOrders.value
  }

  // 待支付订单剩余支付时间 mm:ss（列表页倒计时展示）
  function remainText(o) {
    if (!o || !o.expiresAt) return ''
    const r = Math.max(0, Math.ceil((o.expiresAt - Date.now()) / 1000))
    const m = String(Math.floor(r / 60)).padStart(2, '0')
    const s = String(r % 60).padStart(2, '0')
    return m + ':' + s
  }

  return { orders, purchaseOrders, createOrder, payOrder, cancelOrder, fetchOrders, fetchPurchaseOrders, remainText }
})
