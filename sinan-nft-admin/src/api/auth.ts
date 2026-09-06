/**
 * 认证相关 API
 */
import { get, post } from './http'
import type { AdminInfo } from '@/stores/auth'

export interface LoginResult {
  token: string
  refresh_token: string
  expire_at: string
  admin: AdminInfo
}

export function login(username: string, password: string): Promise<LoginResult> {
  return post<LoginResult>('/auth/login', { username, password })
}

export function logout(): Promise<null> {
  return post<null>('/auth/logout')
}

export function fetchProfile(): Promise<AdminInfo | null> {
  return get<AdminInfo | null>('/auth/profile')
}

export function changePassword(oldPassword: string, newPassword: string, confirmPassword: string): Promise<null> {
  return post<null>('/auth/change-password', {
    old_password: oldPassword,
    new_password: newPassword,
    confirm_password: confirmPassword,
  })
}
