/**
 * 用户 / 实名 API
 */
import { get, post } from './http'
import type { PageResult } from '@/utils/useListPage'

// ===== 用户管理 =====
export function fetchUsers(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/users', params)
}

export function fetchUserDetail(id: number): Promise<any> {
  return get(`/users/${id}`)
}

export function freezeUser(id: number, reason: string): Promise<any> {
  return post(`/users/${id}/freeze`, { reason })
}

export function unfreezeUser(id: number, reason: string): Promise<any> {
  return post(`/users/${id}/freeze`, { reason, action: 'unfreeze' })
}

export function resetUserTransactionPassword(id: number, reason: string): Promise<any> {
  return post(`/users/${id}/reset-transaction-password`, { reason })
}

export function forceLogout(id: number, reason: string): Promise<any> {
  return post(`/users/${id}/force-logout`, { reason })
}

export function blacklistUser(id: number, reason: string): Promise<any> {
  return post(`/users/${id}/blacklist`, { reason })
}

export function removeBlacklist(id: number): Promise<any> {
  return post(`/users/${id}/blacklist`, { action: 'remove' })
}

export function recoverUserAssets(id: number, reason: string): Promise<any> {
  return post('/users/recover', { user_id: id, reason })
}

// ===== 实名认证 =====
export function fetchRealnameUsers(params: Record<string, any>): Promise<PageResult> {
  return get<PageResult>('/realname/users', params)
}

export function fetchRealnameDetail(userId: number): Promise<any> {
  return get(`/realname/users/${userId}`)
}

export function fetchRealnameStats(): Promise<any> {
  return get('/realname/stats')
}
