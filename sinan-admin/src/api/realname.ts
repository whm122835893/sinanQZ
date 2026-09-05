// ============================================================================
// 实名认证接口（文档 8.5，4 个只读 + 完整查看，#29~#32）
// 手机号/姓名/身份证强制脱敏；完整查看需密码并写审计（文档 11.1）
// ============================================================================
import { get, post } from '@/utils/request'
import type { PageData, RealnameAuditLog, RealnameDetail, RealnameFull, RealnameRow } from '@/types/api'

export interface RealnameQuery {
  page?: number
  pageSize?: number
  /** 姓名包含匹配（后端解密后内存过滤） */
  name?: string
  /** 手机号模糊匹配 */
  phone?: string
}

/** #29 实名列表（默认脱敏） */
export function fetchRealnames(params: RealnameQuery): Promise<PageData<RealnameRow>> {
  return get<PageData<RealnameRow>>('/realnames', params as Record<string, unknown>)
}

/** #30 实名详情（脱敏） */
export function fetchRealnameDetail(id: number | string): Promise<RealnameDetail> {
  return get<RealnameDetail>(`/realnames/${id}`)
}

/** #31 查看完整实名（password，写审计日志） */
export function fetchRealnameFull(id: number | string, password: string): Promise<RealnameFull> {
  return post<RealnameFull>(`/realnames/${id}/full`, { password })
}

/** #32 实名查看审计日志 */
export function fetchRealnameAuditLogs(
  id: number | string,
  params?: { page?: number; pageSize?: number }
): Promise<PageData<RealnameAuditLog>> {
  return get<PageData<RealnameAuditLog>>(`/realnames/${id}/audit-logs`, params as Record<string, unknown>)
}
