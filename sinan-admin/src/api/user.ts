// ============================================================================
// 用户管理接口（文档 8.4，15 个：P0 基础 + P1 资产/黑名单/回收）
// ============================================================================
import { get, put, post, del } from '@/utils/request'
import type {
  PageData,
  RecoverRevert,
  UserCollectibleRow,
  UserDetail,
  UserInviteResult,
  UserQualificationResult,
  UserRow,
  UserWalletResult,
  UserBlindboxRow
} from '@/types/api'

export interface UserQuery {
  page?: number
  pageSize?: number
  phone?: string
  username?: string
  uid?: string
  status?: string
  isRealname?: string
  createdAtStart?: string
  createdAtEnd?: string
}

/** 用户列表 */
export function fetchUsers(params: UserQuery): Promise<PageData<UserRow>> {
  return get<PageData<UserRow>>('/users', params as Record<string, unknown>)
}

/** 用户详情 */
export function fetchUserDetail(id: number | string): Promise<UserDetail> {
  return get<UserDetail>(`/users/${id}`)
}

/** 冻结账号 */
export function freezeUser(id: number | string, reason: string): Promise<null> {
  return put<null>(`/users/${id}/freeze`, { reason })
}

/** 解冻账号 */
export function unfreezeUser(id: number | string): Promise<null> {
  return put<null>(`/users/${id}/unfreeze`, {})
}

/** #19 重置交易密码（reason） */
export function resetTxPassword(id: number | string, reason: string): Promise<null> {
  return put<null>(`/users/${id}/reset-tx-password`, { reason })
}

/** #20 强制登出（reason，踢出全部登录态） */
export function forceLogoutUser(id: number | string, reason: string): Promise<null> {
  return put<null>(`/users/${id}/force-logout`, { reason })
}

/** #21 加入黑名单（reason/expiresAt?） */
export function addBlacklist(
  id: number | string,
  data: { reason: string; expiresAt?: string | null }
): Promise<null> {
  return post<null>(`/users/${id}/blacklist`, data as unknown as Record<string, unknown>)
}

/** #22 移出黑名单（reason） */
export function removeBlacklist(id: number | string, reason: string): Promise<null> {
  return del<null>(`/users/${id}/blacklist`, { reason })
}

/** #23 用户钱包资产与流水（type 可按 trans_type 过滤） */
export function fetchUserWallet(
  id: number | string,
  params?: { page?: number; pageSize?: number; type?: string }
): Promise<UserWalletResult> {
  return get<UserWalletResult>(`/users/${id}/wallet`, params as Record<string, unknown>)
}

/** #24 用户仓库藏品（分页，status 可过滤持仓状态） */
export function fetchUserCollectibles(
  id: number | string,
  params?: { page?: number; pageSize?: number; status?: string }
): Promise<PageData<UserCollectibleRow>> {
  return get<PageData<UserCollectibleRow>>(`/users/${id}/collectibles`, params as Record<string, unknown>)
}

/** #25 用户仓库盲盒（分页） */
export function fetchUserBlindboxes(
  id: number | string,
  params?: { page?: number; pageSize?: number }
): Promise<PageData<UserBlindboxRow>> {
  return get<PageData<UserBlindboxRow>>(`/users/${id}/blindboxes`, params as Record<string, unknown>)
}

/** #26 用户优先购资格（分页） */
export function fetchUserQualifications(
  id: number | string,
  params?: { page?: number; pageSize?: number }
): Promise<UserQualificationResult> {
  return get<UserQualificationResult>(`/users/${id}/priority-qualifications`, params as Record<string, unknown>)
}

/** #27 用户邀请记录（分页） */
export function fetchUserInvites(
  id: number | string,
  params?: { page?: number; pageSize?: number }
): Promise<UserInviteResult> {
  return get<UserInviteResult>(`/users/${id}/invites`, params as Record<string, unknown>)
}

/** #28 强制回收藏品（reason + password，校验二次流转） */
export function recoverCollectible(
  id: number | string,
  data: { userCollectibleId: number; reason: string; password: string }
): Promise<{ revert: RecoverRevert }> {
  return post<{ revert: RecoverRevert }>(`/users/${id}/recover-collectible`, data as unknown as Record<string, unknown>)
}

/** #29 强制回收盲盒（reason + password，校验是否已开启） */
export function recoverBlindbox(
  id: number | string,
  data: { userBlindboxId: number; reason: string; password: string }
): Promise<{ revert: RecoverRevert }> {
  return post<{ revert: RecoverRevert }>(`/users/${id}/recover-blindbox`, data as unknown as Record<string, unknown>)
}
