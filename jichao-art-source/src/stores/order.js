import { defineStore } from 'pinia'
import { ref } from 'vue'

// 订单状态：我的订单 / 申购订单（按规范默认空状态）
export const useOrderStore = defineStore('order', () => {
  const orders = ref([])          // 我的订单
  const purchaseOrders = ref([])  // 申购订单

  function fetchOrders() {
    orders.value = []
    return Promise.resolve([])
  }

  function fetchPurchaseOrders() {
    purchaseOrders.value = []
    return Promise.resolve([])
  }

  return { orders, purchaseOrders, fetchOrders, fetchPurchaseOrders }
})
