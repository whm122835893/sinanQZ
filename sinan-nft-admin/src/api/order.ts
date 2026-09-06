/**
 * 订单 / 退款 / 市场 / 转赠 API
 */
import { get, post, put, getRaw } from './http'
import type { PageResult } from '@/utils/useListPage'

// ===== 订单 =====
export function fetchOrders(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/orders', params)
}

export function fetchOrderDetail(id: number): Promise<any> {
  return get(`/orders/${id}`)
}

export function cancelOrder(id: number, reason: string): Promise<any> {
  return post(`/orders/${id}/cancel`, { reason })
}

export function markOrderPaid(id: number, data: Record<string, any>): Promise<any> {
  return post(`/orders/${id}/mark-paid`, data)
}

export function refundOrder(id: number, data: Record<string, any>): Promise<any> {
  return post(`/orders/${id}/refund`, data)
}

export function fetchOrderAudit(params?: Record<string, any>): Promise<any> {
  return get('/orders/audit', params)
}

export function exportOrderAudit(params?: Record<string, any>): Promise<string> {
  return getRaw('/orders/audit', { ...params, export: 'csv' })
}

// ===== 退款 =====
export function fetchRefunds(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/refunds', params)
}

export function fetchRefundDetail(id: number): Promise<any> {
  return get(`/refunds/${id}`)
}

export function approveRefund(id: number, data: Record<string, any>): Promise<any> {
  return post(`/refunds/${id}/approve`, data)
}

export function executeRefund(id: number, data: Record<string, any>): Promise<any> {
  return post(`/refunds/${id}/execute`, data)
}

// ===== 市场寄售 =====
export function fetchListings(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/market/listings', params)
}

export function manageListing(id: number, data: Record<string, any>): Promise<any> {
  return post(`/market/listings/${id}/manage`, data)
}

export function fetchMarketConfig(): Promise<any> {
  return get('/market/config')
}

export function saveMarketConfig(data: Record<string, any>): Promise<any> {
  return post('/market/config', data)
}

// ===== 转赠 =====
export function fetchTransfers(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/transfers', params)
}

export function revokeTransfer(id: number, reason: string): Promise<any> {
  return post(`/transfers/${id}/revoke`, { reason })
}
