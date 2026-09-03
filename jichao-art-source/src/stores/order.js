import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useCollectionStore } from './collection'

// 订单状态：我的订单 / 申购订单
export const useOrderStore = defineStore('order', () => {
  const orders = ref([])          // 我的订单
  const purchaseOrders = ref([])  // 申购订单

  // 待支付订单有效期：5 分钟，超时自动取消并释放锁定的库存
  const PAY_TIMEOUT = 5 * 60 * 1000

  // 生成订单号：JC + 年月日时分秒 + 3 位随机
  function genOrderNo() {
    const d = new Date()
    const p = (n, l = 2) => String(n).padStart(l, '0')
    return 'JC' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds()) + String(Math.floor(Math.random() * 900) + 100)
  }

  // 支付成功后写入订单（购买成功由 Pay 页调用）
  function addOrder(payload) {
    const order = {
      id: genOrderNo(),
      itemId: payload.id,
      cover: payload.coverImage,
      name: payload.name,
      no: payload.no || '',
      price: Number(payload.price),
      qty: payload.qty || 1,
      // 发售订单 release / 挂单订单 resale，支付成功即已完成
      kind: payload.kind || 'release',
      status: 'done',
      createdAt: Date.now()
    }
    orders.value.unshift(order)
    return order
  }

  // 创建待支付订单（进入支付页时调用；发售模式同步锁定藏品库存）
  function addPendingOrder(payload) {
    const order = {
      id: genOrderNo(),
      itemId: payload.id,
      cover: payload.coverImage,
      name: payload.name,
      no: payload.no || '',
      price: Number(payload.price),
      qty: payload.qty || 1,
      kind: payload.kind || 'release',
      status: 'pending',
      createdAt: Date.now(),
      expiresAt: Date.now() + PAY_TIMEOUT
    }
    orders.value.unshift(order)
    return order.id
  }

  // 支付成功：待支付订单 -> 已完成
  function completeOrder(orderId) {
    const o = orders.value.find(o => o.id === orderId)
    if (o && o.status === 'pending') o.status = 'done'
  }

  // 取消订单（幂等）：发售订单释放锁定的库存
  function cancelOrder(orderId) {
    const o = orders.value.find(o => o.id === orderId)
    if (!o || o.status !== 'pending') return
    o.status = 'cancelled'
    if (o.kind === 'release') {
      const collection = useCollectionStore()
      collection.changeStock(o.itemId, o.qty)
    }
  }

  // 超时未支付的订单自动取消（支付页/订单页轮询调用）
  function expireOrders() {
    const now = Date.now()
    orders.value
      .filter(o => o.status === 'pending' && now >= o.expiresAt)
      .forEach(o => cancelOrder(o.id))
  }

  // 更新待支付订单数量（支付页调整购买数量时同步）
  function updatePendingQty(orderId, qty) {
    const o = orders.value.find(o => o.id === orderId)
    if (o && o.status === 'pending') o.qty = qty
  }

  function fetchOrders() {
    return Promise.resolve(orders.value)
  }

  function fetchPurchaseOrders() {
    purchaseOrders.value = []
    return Promise.resolve([])
  }

  return { orders, purchaseOrders, genOrderNo, addOrder, addPendingOrder, completeOrder, cancelOrder, expireOrders, updatePendingQty, fetchOrders, fetchPurchaseOrders }
})
