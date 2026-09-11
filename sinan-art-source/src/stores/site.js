import { defineStore } from 'pinia'
import request from '@/utils/request'

// ============================================================
// 站点装修（B 端配置的全局风格）→ C 端运行时应用
// - 颜色/圆角：写入 :root CSS 变量，全站（含 Vant）即时生效
// - 名称/头像：品牌展示位引用；SEO 覆盖 document.title
// - localStorage 缓存秒开，接口返回后覆盖
// ============================================================

const CACHE_KEY = 'jc_site_cfg'
const DEFAULTS = {
  siteName: '',           // 空=展示小篆"千年司南｜一器载道"图；运营在 B 端填 siteName 后覆盖
  siteLogo: '',
  siteAvatar: '',
  themeColor: '#C00000',
  bgColor: '#F7F8FA',
  buttonColor: '',
  buttonRadius: 24,
  seoTitle: '',
  seoDescription: '',
  seoKeywords: ''
}

/** HEX 颜色与白色混合（amount 0~1，越大越浅） */
function mixWhite(hex, amount) {
  const n = parseInt(hex.slice(1), 16)
  const r = Math.round(((n >> 16) & 255) + (255 - ((n >> 16) & 255)) * amount)
  const g = Math.round(((n >> 8) & 255) + (255 - ((n >> 8) & 255)) * amount)
  const b = Math.round((n & 255) + (255 - (n & 255)) * amount)
  return `#${((r << 16) | (g << 8) | b).toString(16).padStart(6, '0')}`
}

/** HEX 颜色按比例加深 */
function darken(hex, amount) {
  const n = parseInt(hex.slice(1), 16)
  const r = Math.round(((n >> 16) & 255) * (1 - amount))
  const g = Math.round(((n >> 8) & 255) * (1 - amount))
  const b = Math.round((n & 255) * (1 - amount))
  return `#${((r << 16) | (g << 8) | b).toString(16).padStart(6, '0')}`
}

function readCache() {
  try {
    return JSON.parse(localStorage.getItem(CACHE_KEY)) || null
  } catch {
    return null
  }
}

export const useSiteStore = defineStore('site', {
  state: () => ({ ...DEFAULTS, ...readCache(), loaded: false }),

  getters: {
    /** 品牌头像（优先平台头像，回退 Logo/默认） */
    brandAvatar: (s) => s.siteAvatar || s.siteLogo || '/images/platform-logo.png',
    /** 实际按钮色（留空跟随主题色） */
    buttonColorValue: (s) => s.buttonColor || s.themeColor
  },

  actions: {
    /** 启动时调用：先应用缓存，再拉取最新配置 */
    async init() {
      this.apply()
      try {
        const cfg = await request.get('/config')
        if (cfg?.site) this.set(cfg.site)
      } catch { /* 网络异常时沿用缓存 */ }
      this.loaded = true
    },

    set(site) {
      const next = {}
      for (const k of Object.keys(DEFAULTS)) {
        if (site[k] !== undefined && site[k] !== null && site[k] !== '') next[k] = site[k]
      }
      // buttonRadius 需为数值
      if (next.buttonRadius !== undefined) next.buttonRadius = Number(next.buttonRadius) || 0
      Object.assign(this, next)
      localStorage.setItem(CACHE_KEY, JSON.stringify({
        siteName: this.siteName,
        siteLogo: this.siteLogo,
        siteAvatar: this.siteAvatar,
        themeColor: this.themeColor,
        bgColor: this.bgColor,
        buttonColor: this.buttonColor,
        buttonRadius: this.buttonRadius,
        seoTitle: this.seoTitle,
        seoDescription: this.seoDescription,
        seoKeywords: this.seoKeywords
      }))
      this.apply()
    },

    /** 将配置写入 :root CSS 变量 + 页面标题/主题色 */
    apply() {
      const root = document.documentElement.style
      const theme = this.themeColor
      const button = this.buttonColorValue

      root.setProperty('--color-primary', theme)
      root.setProperty('--color-primary-dark', darken(theme, 0.15))
      root.setProperty('--color-primary-light', mixWhite(theme, 0.85))
      root.setProperty('--color-primary-bg', mixWhite(theme, 0.96))
      root.setProperty('--color-bg', this.bgColor)
      root.setProperty('--color-button', button)
      root.setProperty('--color-button-dark', darken(button, 0.15))
      root.setProperty('--radius-btn', `${this.buttonRadius}px`)

      // 页面标题与浏览器主题色
      document.title = this.seoTitle || `${this.siteName} · SINAN DIGITAL COLLECTION`
      document.querySelector('meta[name="theme-color"]')
        ?.setAttribute('content', theme)
    }
  }
})
