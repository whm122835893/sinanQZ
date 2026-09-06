import { defineStore } from 'pinia'

const TOKEN_KEY = 'sinan_admin_token'
const INFO_KEY = 'sinan_admin_info'

// ============================================================
// 管理员会话（结构对齐后端 admin JWT：token + 管理员信息 + 角色 + 权限码）
// 权限码由后端下发（permissions 数组），超管为 ['*']
// ============================================================

// 各角色权限码预置（联调后由后端 nft_admin_role_permissions 下发）
export const ROLE_PERMISSIONS = {
  super: ['*'],
  operator: [
    'dashboard', 'statistics', 'user', 'user.freeze',
    'collectible', 'blindbox', 'order', 'resale', 'transfer',
    'marketing', 'content'
  ],
  finance: ['dashboard', 'statistics', 'order', 'refund', 'wallet'],
  risk: [
    'dashboard', 'user', 'user.blacklist', 'user.recover',
    'realname', 'realname.full', 'risk', 'resale', 'transfer'
  ],
  support: ['dashboard', 'tickets', 'user']
}

export const useAdminStore = defineStore('admin', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    info: JSON.parse(localStorage.getItem(INFO_KEY) || 'null')
  }),
  getters: {
    isLogged: (s) => !!s.token,
    displayName: (s) => s.info?.name || s.info?.username || '管理员',
    role: (s) => s.info?.role || 'operator',
    roleLabel() {
      const map = { super: '超级管理员', operator: '运营专员', finance: '财务专员', risk: '风控专员', support: '客服专员' }
      return map[this.role] || '管理员'
    },
    // 当前管理员权限码（含超管通配）
    permissions() {
      return ROLE_PERMISSIONS[this.role] || []
    },
    hasPermission() {
      return (code) => this.permissions.includes('*') || this.permissions.includes(code)
    }
  },
  actions: {
    setSession({ token, admin }) {
      this.token = token
      this.info = admin
      localStorage.setItem(TOKEN_KEY, token)
      localStorage.setItem(INFO_KEY, JSON.stringify(admin))
    },
    clearSession() {
      this.token = ''
      this.info = null
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(INFO_KEY)
    }
  }
})
