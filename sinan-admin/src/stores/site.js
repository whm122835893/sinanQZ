import { defineStore } from 'pinia'
import { getSiteBrand } from '@/api'

// 站点品牌：B 端展示的站点名/头像（与 C 端装修配置同源，保存后同步）
export const useSiteStore = defineStore('site', {
  state: () => ({
    siteName: localStorage.getItem('adm_site_name') || '司南珍藏',
    siteAvatar: localStorage.getItem('adm_site_avatar') || '',
    loaded: false
  }),
  getters: {
    // 侧边栏/登录页 Logo：优先装修配置的头像，回退默认平台图
    brandLogo: (s) => s.siteAvatar || '/images/platform-logo.png'
  },
  actions: {
    /** 启动时拉取（公开接口，登录页也可用）；失败沿用本地缓存 */
    async fetchBrand() {
      try {
        const res = await getSiteBrand()
        if (res.code === 0 && res.data) {
          this.setBrand(res.data.siteName, res.data.siteAvatar)
        }
      } catch { /* 网络异常时静默，保持默认 */ }
      this.loaded = true
    },
    setBrand(name, avatar) {
      if (name) {
        this.siteName = name
        localStorage.setItem('adm_site_name', name)
      }
      this.siteAvatar = avatar || ''
      localStorage.setItem('adm_site_avatar', this.siteAvatar)
    }
  }
})
