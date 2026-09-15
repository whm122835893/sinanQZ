import { defineStore } from 'pinia'
import request from '@/utils/request'
import { useIconThemeStore } from './iconTheme'

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
  seoKeywords: '',
  // 开屏配置（B 端装修页配置；未启用或未配置时 C 端不展示）
  splashEnabled: false,
  splashImage: '',
  splashDuration: 3,
  // 登录页协议（B 端「内容 → 协议管理」维护；空则使用内置兜底文案）
  agreement: '',
  privacy: ''
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
  state: () => ({ ...DEFAULTS, purchaseLimitPerUser: 5, ...readCache(), loaded: false }),

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
        // 全局限购数（与后端 perUserLimit 兜底同源：purchase_limit_per_user）
        if (cfg?.purchaseLimitPerUser) this.purchaseLimitPerUser = Number(cfg.purchaseLimitPerUser) || 5
        // 图标主题：后台配置的图标风格包（功能图标与底部导航可独立切换）+ 自定义图标
        const iconTheme = useIconThemeStore()
        if (cfg?.site?.featureIconTheme) iconTheme.setFeatureTheme(cfg.site.featureIconTheme)
        if (cfg?.site?.tabIconTheme) iconTheme.setTabTheme(cfg.site.tabIconTheme)
        if (cfg?.site?.customIcons) iconTheme.setCustomIcons(cfg.site.customIcons)
      } catch { /* 网络异常时沿用缓存 */ }
      this.loaded = true
    },

    set(site) {
      const next = {}
      for (const k of Object.keys(DEFAULTS)) {
        if (site[k] !== undefined && site[k] !== null && site[k] !== '') next[k] = site[k]
      }
      // 以下字段允许为空（B 端清空后 C 端应立即生效，而非沿用缓存旧值）
      // - siteName: 清空站点名后回退展示小篆图
      // - siteLogo/siteAvatar: 删除品牌图后回退默认
      // - buttonColor: 清空后跟主题色
      // - splashImage: 关闭开屏或删除图后 C 端不展示
      // - seoTitle/seoDescription/seoKeywords: SEO 字段允许空
      const ALLOW_EMPTY = ['siteName', 'siteLogo', 'siteAvatar', 'buttonColor', 'splashImage', 'seoTitle', 'seoDescription', 'seoKeywords', 'agreement', 'privacy']
      for (const k of ALLOW_EMPTY) {
        if (site[k] !== undefined && site[k] !== null) next[k] = site[k]
      }
      // buttonRadius / splashDuration 需为数值
      if (next.buttonRadius !== undefined) next.buttonRadius = Number(next.buttonRadius) || 0
      if (next.splashDuration !== undefined) next.splashDuration = Math.max(1, Math.min(10, Number(next.splashDuration) || 3))
      // splashEnabled 需为布尔值（后端 API 已返回 bool，这里兜底）
      if (next.splashEnabled !== undefined) next.splashEnabled = !!next.splashEnabled
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
        seoKeywords: this.seoKeywords,
        splashEnabled: this.splashEnabled,
        splashImage: this.splashImage,
        splashDuration: this.splashDuration
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
