/**
 * 认证状态（Pinia）
 *
 * - token / refresh_token 持久化 localStorage
 * - adminInfo 含权限码集合与菜单树（登录时一次性下发）
 * - hasPermission() 供路由守卫与按钮级 v-permission 指令使用
 */
import { defineStore } from 'pinia'
import { login as apiLogin, logout as apiLogout, fetchProfile } from '@/api/auth'

export interface MenuItem {
  id: number
  name: string
  code: string
  path: string
  icon: string
  sort: number
  children?: MenuItem[]
}

export interface AdminInfo {
  id: number
  username: string
  realName: string
  avatar?: string
  roleId: number
  roleName: string
  roleCode: string
  isSuper: boolean
  permissions: string[]
  menus: MenuItem[]
  lastLoginAt?: string
  lastLoginIp?: string
}

interface AuthState {
  token: string
  refreshToken: string
  expireAt: string
  adminInfo: AdminInfo | null
}

const STORAGE_KEY = 'sinan-admin-auth'

function loadState(): AuthState {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw) return JSON.parse(raw) as AuthState
  } catch {
    /* ignore */
  }
  return { token: '', refreshToken: '', expireAt: '', adminInfo: null }
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => loadState(),

  getters: {
    isLoggedIn: (s) => !!s.token && !!s.adminInfo,
    permissions: (s) => s.adminInfo?.permissions ?? [],
    isSuper: (s) => !!s.adminInfo?.isSuper,
    menus: (s) => s.adminInfo?.menus ?? [],
    displayName: (s) => s.adminInfo?.realName || s.adminInfo?.username || '管理员',
  },

  actions: {
    persist() {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({
          token: this.token,
          refreshToken: this.refreshToken,
          expireAt: this.expireAt,
          adminInfo: this.adminInfo,
        }),
      )
    },

    async login(username: string, password: string) {
      const data = await apiLogin(username, password)
      this.token = data.token
      this.refreshToken = data.refresh_token
      this.expireAt = data.expire_at
      this.adminInfo = data.admin
      this.persist()
    },

    /** 路由守卫静默恢复：拉取最新权限（管理员被改权限/禁用即刻生效） */
    async refreshProfile() {
      const info = await fetchProfile()
      if (info && this.adminInfo) {
        this.adminInfo = { ...this.adminInfo, ...info }
        this.persist()
      }
    },

    hasPermission(code?: string): boolean {
      if (!code) return true
      if (this.isSuper) return true
      return this.permissions.includes(code)
    },

    async logout(callApi = true) {
      if (callApi && this.token) {
        try {
          await apiLogout()
        } catch {
          /* 退出登录失败不阻断本地清理 */
        }
      }
      this.token = ''
      this.refreshToken = ''
      this.expireAt = ''
      this.adminInfo = null
      localStorage.removeItem(STORAGE_KEY)
    },
  },
})
