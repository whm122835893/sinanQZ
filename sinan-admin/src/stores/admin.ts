// ============================================================================
// 管理员会话状态：token 持久化 / 管理员信息 / 权限码集合
// ============================================================================
import { defineStore } from 'pinia'
import type { AdminProfile } from '@/types/api'
import { getToken, getRefreshToken, setTokens, clearTokens } from '@/utils/request'

export const useAdminStore = defineStore('admin', {
  state: () => ({
    token: getToken(),
    refreshToken: getRefreshToken(),
    admin: null as AdminProfile | null,
    permissions: [] as string[],
    mustChangePwd: false
  }),

  getters: {
    isLoggedIn: (state): boolean => state.token !== '',
    hasPermission: (state): ((perm: string) => boolean) => {
      return (perm: string): boolean => {
        if (!perm) return true
        return state.permissions.includes(perm)
      }
    }
  },

  actions: {
    setLogin(payload: {
      token: string
      refreshToken: string
      admin: AdminProfile
      permissions: string[]
      mustChangePwd?: boolean
    }): void {
      this.token = payload.token
      this.refreshToken = payload.refreshToken
      this.admin = payload.admin
      this.permissions = payload.permissions
      this.mustChangePwd = payload.mustChangePwd ?? false
      setTokens(payload.token, payload.refreshToken)
    },

    setMe(admin: AdminProfile, permissions: string[]): void {
      this.admin = admin
      this.permissions = permissions
    },

    reset(): void {
      this.token = ''
      this.refreshToken = ''
      this.admin = null
      this.permissions = []
      this.mustChangePwd = false
      clearTokens()
    }
  }
})
