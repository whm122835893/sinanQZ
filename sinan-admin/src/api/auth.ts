// ============================================================================
// 认证接口（文档 8.2，P0 子集）
// ============================================================================
import { get, post, put } from '@/utils/request'
import type { LoginResult, MeResult } from '@/types/api'

/** 登录 */
export function login(payload: { username: string; password: string }): Promise<LoginResult> {
  return post<LoginResult>('/auth/login', payload)
}

/** 登出 */
export function logout(): Promise<null> {
  return post<null>('/auth/logout')
}

/** 刷新 Token */
export function refreshToken(payload: { refreshToken: string }): Promise<{ token: string; refreshToken: string }> {
  return post<{ token: string; refreshToken: string }>('/auth/refresh', payload)
}

/** 当前管理员信息 + 权限 */
export function fetchMe(): Promise<MeResult> {
  return get<MeResult>('/auth/me')
}

/** 修改密码 */
export function changePassword(payload: { oldPassword: string; newPassword: string }): Promise<null> {
  return put<null>('/auth/password', payload)
}
