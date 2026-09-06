/**
 * 权限管理 API（管理员/角色/日志）
 */
import { get, post, put, del } from './http'
import type { PageResult } from '@/utils/useListPage'

// ===== 管理员 =====
export function fetchAdmins(params?: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/permission/admins', params)
}

export function createAdmin(data: Record<string, any>): Promise<any> {
  return post('/permission/admins', data)
}

export function updateAdmin(id: number, data: Record<string, any>): Promise<any> {
  return put(`/permission/admins/${id}`, data)
}

export function resetAdminPassword(id: number, data: Record<string, any>): Promise<any> {
  return post(`/permission/admins/${id}/reset-password`, data)
}

export function unlockAdmin(id: number): Promise<any> {
  return post(`/permission/admins/${id}/unlock`)
}

export function deleteAdmin(id: number): Promise<any> {
  return del(`/permission/admins/${id}`)
}

// ===== 角色 =====
export function fetchRoles(params?: Record<string, any>): Promise<any> {
  return get('/permission/roles', params)
}

export function fetchRoleDetail(id: number): Promise<any> {
  return get(`/permission/roles/${id}`)
}

export function fetchPermissionTree(): Promise<any[]> {
  return get<any[]>('/permission/tree')
}

export function createRole(data: Record<string, any>): Promise<any> {
  return post('/permission/roles', data)
}

export function updateRole(id: number, data: Record<string, any>): Promise<any> {
  return put(`/permission/roles/${id}`, data)
}

export function deleteRole(id: number): Promise<any> {
  return del(`/permission/roles/${id}`)
}

// ===== 日志 =====
export function fetchOperationLogs(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/permission/operation-logs', params)
}

export function fetchLogModules(): Promise<string[]> {
  return get<string[]>('/permission/log-modules')
}

export function fetchLoginLogs(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/permission/login-logs', params)
}
