/**
 * 钱包财务 API
 */
import { get } from './http'
import type { PageResult } from '@/utils/useListPage'

export function fetchTransactions(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/wallet/transactions', params)
}

export function fetchRechargeRecords(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/wallet/recharge', params)
}

export function fetchFeeStats(params?: Record<string, any>): Promise<any> {
  return get('/wallet/fee', params)
}

export function fetchWalletAudit(): Promise<any> {
  return get('/wallet/audit')
}

export function fetchAbnormalFunds(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/wallet/abnormal', params)
}
