/**
 * 风控安全 / 客服工单 / 数据报表 / 平台运维 API
 */
import { get, post } from './http'
import type { PageResult } from '@/utils/useListPage'

// ===== 风控安全 =====
export function fetchBlacklist(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/security/blacklist', params)
}

export function addBlacklist(data: Record<string, any>): Promise<any> {
  return post('/security/blacklist', data)
}

export function liftBlacklist(id: number, reason: string): Promise<any> {
  return post(`/security/blacklist/${id}/lift`, { reason })
}

export function fetchRiskAlerts(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/security/risk-alerts', params)
}

export function handleRiskAlert(id: number, data: Record<string, any>): Promise<any> {
  return post(`/security/risk-alerts/${id}/handle`, data)
}

export function fetchSecurityEvents(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/security/events', params)
}

export function handleSecurityEvent(id: number, data: Record<string, any>): Promise<any> {
  return post(`/security/events/${id}/handle`, data)
}

// ===== 客服工单 =====
export function fetchTickets(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/tickets', params)
}

export function fetchTicketDetail(id: number): Promise<any> {
  return get(`/tickets/${id}`)
}

export function assignTicket(id: number, data: Record<string, any>): Promise<any> {
  return post(`/tickets/${id}/assign`, data)
}

export function replyTicket(id: number, data: Record<string, any>): Promise<any> {
  return post(`/tickets/${id}/reply`, data)
}

export function changeTicketStatus(id: number, data: Record<string, any>): Promise<any> {
  return post(`/tickets/${id}/status`, data)
}

// ===== 数据报表 =====
export function fetchSalesReport(params?: Record<string, any>): Promise<any> {
  return get('/reports/sales', params)
}

export function fetchUserReport(params?: Record<string, any>): Promise<any> {
  return get('/reports/users', params)
}

export function fetchCollectibleReport(params?: Record<string, any>): Promise<any> {
  return get('/reports/collectibles', params)
}

export function fetchBlindboxReport(params?: Record<string, any>): Promise<any> {
  return get('/reports/blindbox', params)
}

export function fetchFinanceReport(params?: Record<string, any>): Promise<any> {
  return get('/reports/finance', params)
}

// ===== 平台运维 =====
export function fetchCleanupLogs(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/platform/cleanup-logs', params)
}

export function fetchCleanupPreview(): Promise<any> {
  return get('/platform/cleanup-preview')
}

export function sendCleanupCode(): Promise<any> {
  return post('/platform/cleanup-send-code')
}

export function executeCleanup(data: Record<string, any>): Promise<any> {
  return post('/platform/cleanup-execute', data)
}
