// ============================================================================
// 仪表盘接口（文档 8.3，6 个：P0 核心 + P1 图表/预警/动态）
// ============================================================================
import { get } from '@/utils/request'
import type {
  ActivityEvent,
  AlertsPanel,
  DashboardMetrics,
  FinanceChart,
  PriorityStats,
  TrendData
} from '@/types/api'

/** #8 核心指标卡片 */
export function fetchMetrics(): Promise<DashboardMetrics> {
  return get<DashboardMetrics>('/dashboard/metrics')
}

/** #9 资金监控图表（days=7/30） */
export function fetchFinanceChart(days: 7 | 30 = 7): Promise<FinanceChart> {
  return get<FinanceChart>('/dashboard/finance', { days })
}

/** #10 库存预警面板 */
export function fetchAlerts(): Promise<AlertsPanel> {
  return get<AlertsPanel>('/dashboard/alerts')
}

/** #11 实时动态滚动（limit 默认 20） */
export function fetchActivities(limit = 20): Promise<{ list: ActivityEvent[] }> {
  return get<{ list: ActivityEvent[] }>('/dashboard/activities', { limit })
}

/** #12 趋势图数据（days + metric=sales/orders/blindbox） */
export function fetchTrends(days: 7 | 30 = 7, metric: 'sales' | 'orders' | 'blindbox' = 'sales'): Promise<TrendData> {
  return get<TrendData>('/dashboard/trends', { days, metric })
}

/** #13 优先购统计（发放总量/已用/剩余） */
export function fetchPriorityStats(): Promise<PriorityStats> {
  return get<PriorityStats>('/dashboard/priority-stats')
}
