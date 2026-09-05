// ============================================================================
// 权限模块接口（文档 8.16，P0 子集：管理员账号 / 日志）
// ============================================================================
import { get, post } from '@/utils/request'
import type { AdminRow, LoginLogRow, OperationLogRow, PageData } from '@/types/api'

export interface AdminQuery {
  page?: number
  pageSize?: number
  username?: string
  role?: string
  status?: string
}

/** 管理员列表 */
export function fetchAdmins(params: AdminQuery): Promise<PageData<AdminRow>> {
  return get<PageData<AdminRow>>('/permission/admins', params as Record<string, unknown>)
}

/** 管理员详情 */
export function fetchAdminDetail(id: number | string): Promise<AdminRow> {
  return get<AdminRow>(`/permission/admins/${id}`)
}

export interface CreateAdminPayload {
  username: string
  password: string
  realName: string
  role: number
  phone?: string
  email?: string
}

/** 创建管理员 */
export function createAdmin(payload: CreateAdminPayload): Promise<{ id: number }> {
  return post<{ id: number }>('/permission/admins', payload as unknown as Record<string, unknown>)
}

export interface OperationLogQuery {
  page?: number
  pageSize?: number
  adminId?: string
  module?: string
  action?: string
  createdAtStart?: string
  createdAtEnd?: string
}

/** 操作日志列表 */
export function fetchOperationLogs(params: OperationLogQuery): Promise<PageData<OperationLogRow>> {
  return get<PageData<OperationLogRow>>('/permission/operation-logs', params as Record<string, unknown>)
}

export interface LoginLogQuery {
  page?: number
  pageSize?: number
  username?: string
  success?: string
  ip?: string
  createdAtStart?: string
  createdAtEnd?: string
}

/** 登录日志列表 */
export function fetchLoginLogs(params: LoginLogQuery): Promise<PageData<LoginLogRow>> {
  return get<PageData<LoginLogRow>>('/permission/login-logs', params as Record<string, unknown>)
}
