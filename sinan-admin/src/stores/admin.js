import { defineStore } from 'pinia'

const TOKEN_KEY = 'sinan_admin_token'
const INFO_KEY = 'sinan_admin_info'

// 管理员会话（结构对齐后端 admin JWT：token + 管理员信息 + 角色）
export const useAdminStore = defineStore('admin', {
  state: () => ({
    token: localStorage.getItem(TOKEN_KEY) || '',
    info: JSON.parse(localStorage.getItem(INFO_KEY) || 'null')
  }),
  getters: {
    isLogged: (s) => !!s.token,
    displayName: (s) => s.info?.name || s.info?.username || '管理员',
    roleLabel() {
      const map = { super: '超级管理员', operator: '运营专员', auditor: '审核专员' }
      return map[this.info?.role] || '管理员'
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
