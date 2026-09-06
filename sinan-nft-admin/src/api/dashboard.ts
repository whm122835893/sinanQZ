/**
 * 仪表盘 API
 */
import { get } from './http'

export interface DashboardOverview {
  [key: string]: any
}

export function fetchOverview(): Promise<DashboardOverview> {
  return get<DashboardOverview>('/dashboard/overview')
}

export function fetchTrend(params?: Record<string, any>): Promise<any> {
  return get('/dashboard/trend', params)
}

export function fetchRank(params?: Record<string, any>): Promise<any> {
  return get('/dashboard/rank', params)
}

export function fetchLatest(params?: Record<string, any>): Promise<any> {
  return get('/dashboard/latest', params)
}
