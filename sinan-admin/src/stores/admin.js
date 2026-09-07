import { defineStore } from 'pinia'

const TOKEN_KEY = 'sinan_admin_token'
const INFO_KEY = 'sinan_admin_info'

// ============================================================
// 管理员会话（联调版：权限码由后端登录接口下发）
// - super_admin 角色后端直接下发全部权限码 + isSuper 标记
// - 其余角色按 nft_admin_role_permissions 下发
// - 前端菜单 perm 为语义化短码（user / resale / content…），
//   通过 ALIAS 映射到后端权限前缀后做「等值或前缀」匹配
// ============================================================

// 后端角色码 → 前端语义角色
const ROLE_CODE_MAP = {
  super_admin: 'super',
  operator: 'operator',
  finance: 'finance',
  risk: 'risk',
  support: 'support'
}

// 前端菜单/按钮 perm → 后端权限前缀（用于菜单可见性与按钮级控制）
// 例：菜单 perm 'resale' → 后端 'market:list'；'user.freeze' → 后端 'user:freeze'
const PERM_ALIAS = {
  statistics: 'report',
  resale: 'market',
  risk: 'security',
  tickets: 'ticket',
  content: 'cms',
  cleanup: 'platform:cleanup',
  // 新增功能：前端短码 → 后端带 module 的完整前缀
  buyrequest: 'market:buyrequest',
  swap: 'market:swap',
  decompose: 'marketing:decompose',
  raffle: 'marketing:raffle',
  trash: 'platform:trash',
  lottery: 'marketing:lucky'
}

// 'system' 汇总权限：任一命中即可见
const SYSTEM_PERMS = ['system:', 'permission:', 'approval:', 'platform:log', 'platform:cleanup']

export const useAdminStore = defineStore('admin', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    info: JSON.parse(localStorage.getItem(INFO_KEY) || 'null')
  }),
  getters: {
    isLogged: (s) => !!s.token,
    displayName: (s) => s.info?.realName || s.info?.name || s.info?.username || '管理员',
    avatar: (s) => s.info?.avatar || '',
    role: (s) => ROLE_CODE_MAP[s.info?.roleCode] || s.info?.role || 'operator',
    roleLabel() {
      const map = { super: '超级管理员', operator: '运营专员', finance: '财务专员', risk: '风控专员', support: '客服专员' }
      return map[this.role] || '管理员'
    },
    // 当前管理员权限码（后端下发；无后端数据时回退本地预置）
    permissions(s) {
      if (Array.isArray(s.info?.permissions) && s.info.permissions.length) {
        return s.info.permissions
      }
      return []
    },
    /**
     * 权限判定：前端语义码 → 后端权限码匹配
     * - isSuper 直接放行
     * - 'user.freeze' → 匹配 'user:freeze'
     * - 'user' → 匹配 'user:list'（任意 user: 前缀）
     * - 'system' → 任一系统管理前缀命中
     */
    hasPermission() {
      const perms = this.permissions
      const isSuper = !!this.info?.isSuper || perms.includes('*')
      return (code) => {
        if (isSuper) return true
        if (!perms.length) return false

        if (code === 'system') {
          return perms.some((p) => SYSTEM_PERMS.some((pre) => p === pre || p.startsWith(pre)))
        }

        const normalized = (PERM_ALIAS[code] || code).replace(/\./g, ':')
        return perms.some((p) => p === normalized || p.startsWith(normalized + ':'))
      }
    }
  },
  actions: {
    // 后端登录响应 data：{ token, refresh_token, expire_at, admin }
    setSession({ token, refresh_token: refreshToken, expire_at: expireAt, admin }) {
      // 归一管理员信息（后端 camelCase：id/username/realName/roleCode/isSuper/permissions/menus）
      const info = {
        id: admin?.id,
        username: admin?.username,
        name: admin?.realName,
        realName: admin?.realName,
        avatar: admin?.avatar,
        phone: admin?.phone,
        email: admin?.email,
        roleCode: admin?.roleCode,
        roleName: admin?.roleName,
        isSuper: !!admin?.isSuper,
        permissions: admin?.permissions || [],
        menus: admin?.menus || [],
        refreshToken: refreshToken || '',
        expireAt: expireAt || ''
      }
      this.token = token
      this.info = info
      localStorage.setItem(TOKEN_KEY, token)
      localStorage.setItem(INFO_KEY, JSON.stringify(info))
    },
    clearSession() {
      this.token = ''
      this.info = null
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem(INFO_KEY)
    }
  }
})
