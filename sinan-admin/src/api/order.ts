// ============================================================================
// 订单管理接口（文档 8.8，8 个，#68~#75）+ 退款审批（文档 8.9，4 个，#76~#79）
// ============================================================================
import { get, post, downloadFile } from '@/utils/request'
import type {
  AbnormalOrderRow,
  OrderDetail,
  OrderRow,
  PageData,
  RefundDetail,
  RefundRow
} from '@/types/api'

export interface OrderQuery {
  page?: number
  pageSize?: number
  orderNo?: string
  userId?: number
  status?: string
  source?: string
  type?: string
  createdAtStart?: string
  createdAtEnd?: string
}

/** #68 订单列表 */
export function fetchOrders(params: OrderQuery): Promise<PageData<OrderRow>> {
  return get<PageData<OrderRow>>('/orders', params as Record<string, unknown>)
}

/** #69 订单详情 */
export function fetchOrderDetail(id: number | string): Promise<OrderDetail> {
  return get<OrderDetail>(`/orders/${id}`)
}

/** #70 强制取消待支付订单（reason + password） */
export function cancelOrder(
  id: number | string,
  data: { reason: string; password: string }
): Promise<null> {
  return post<null>(`/orders/${id}/cancel`, data as unknown as Record<string, unknown>)
}

/** #71 标记已支付（补单：reason + method + password） */
export function markOrderPaid(
  id: number | string,
  data: { reason: string; method: string; password: string }
): Promise<null> {
  return post<null>(`/orders/${id}/mark-paid`, data as unknown as Record<string, unknown>)
}

/** #72 发起退款（amount + reason） */
export function createRefund(
  id: number | string,
  data: { amount: string; reason: string }
): Promise<{ refundId: number; refundNo: string }> {
  return post(`/orders/${id}/refund`, data as unknown as Record<string, unknown>)
}

/** #73 异常订单列表（type=missing_asset/duplicate_charge/amount_mismatch） */
export function fetchAbnormalOrders(params?: {
  page?: number
  pageSize?: number
  type?: string
}): Promise<PageData<AbnormalOrderRow>> {
  return get<PageData<AbnormalOrderRow>>('/orders/abnormal', params as Record<string, unknown>)
}

/** #74 补单修复（repairType + reason + password） */
export function repairOrder(
  id: number | string,
  data: { repairType: string; reason: string; password: string }
): Promise<{ repairedAssets: number } | { status: string } | { before: string; after: string }> {
  return post(`/orders/${id}/repair`, data as unknown as Record<string, unknown>)
}

/** #75 导出订单（后端 CSV 流式下载） */
export function exportOrders(params: Omit<OrderQuery, 'page' | 'pageSize'>): Promise<void> {
  return downloadFile('/orders/export', params as Record<string, unknown>, `orders_${Date.now()}.csv`)
}

// ---------------------------------------------------------------------------
// 退款审批（#76~#79）
// ---------------------------------------------------------------------------

export interface RefundQuery {
  page?: number
  pageSize?: number
  refundNo?: string
  status?: number
  createdAtStart?: string
  createdAtEnd?: string
}

/** #76 退款列表 */
export function fetchRefunds(params: RefundQuery): Promise<PageData<RefundRow>> {
  return get<PageData<RefundRow>>('/refunds', params as Record<string, unknown>)
}

/** #77 退款详情 */
export function fetchRefundDetail(id: number | string): Promise<RefundDetail> {
  return get<RefundDetail>(`/refunds/${id}`)
}

/** #78 批准退款（comment + password，回收资产 + 回退计数器 + 原路退款） */
export function approveRefund(
  id: number | string,
  data: { comment: string; password: string }
): Promise<{
  refundNo: string
  channel: string
  recoveredAssets: number
  revertDetails: Array<{ source: string; reverted: boolean; counter: string; assetId: number }>
}> {
  return post(`/refunds/${id}/approve`, data as unknown as Record<string, unknown>)
}

/** #79 拒绝退款（comment + password） */
export function rejectRefund(
  id: number | string,
  data: { comment: string; password: string }
): Promise<null> {
  return post<null>(`/refunds/${id}/reject`, data as unknown as Record<string, unknown>)
}
