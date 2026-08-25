import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

// ============================================================
// 底部导航图标主题（Theme）—— 可扩展，供后期管理员后台切换
//
// 设计目标：
//   1. 将「导航图标风格」从 AppTabBar 组件中解耦，以主题包形式集中管理
//   2. 每个主题包定义该风格下 4 个 Tab 的图标资源（SVG 矢量名 / 位图路径）
//   3. 当前激活主题持久化到 localStorage，刷新不丢失
//   4. 预留 setTheme() 方法，后期接入管理后台即可一键切换全站风格
//
// 新增风格只需在 ICON_THEMES 中追加一个条目即可，无需改组件代码。
// ============================================================

/**
 * 图标主题包定义
 * @typedef {Object} IconTheme
 * @property {string}  id    - 主题唯一标识
 * @property {string}  name  - 主题显示名称（供后台展示）
 * @property {string}  type  - 渲染方式：'svg' 矢量 | 'image' 位图
 * @property {Object}  tabs  - 各 Tab 的图标配置
 * @property {Object}  tabs.home   - { icon?: string } 或 { image?: string, imageActive?: string }
 * @property {Object}  tabs.market
 * @property {Object}  tabs.notice
 * @property {Object}  tabs.user
 */
const ICON_THEMES = {
  // ---- 经典矢量风（原版 SVG 线性图标，AppIcon 组件渲染）----
  classic: {
    id: 'classic',
    name: '经典矢量',
    type: 'svg',
    tabs: {
      home:   { icon: 'home' },
      market: { icon: 'bag' },
      notice: { icon: 'bell' },
      user:   { icon: 'person' }
    }
  },

  // ---- 3D 宝石质感风（AI 生成位图，PNG 带透明背景）----
  gem: {
    id: 'gem',
    name: '3D宝石质感',
    type: 'image',
    tabs: {
      home:   { image: '/images/tab/tab-home.png',         imageActive: '/images/tab/tab-home-active.png' },
      market: { image: '/images/tab/tab-bag.png',           imageActive: '/images/tab/tab-bag-active.png' },
      notice: { image: '/images/tab/tab-bell.png',          imageActive: '/images/tab/tab-bell-active.png' },
      user:   { image: '/images/tab/tab-person.png',        imageActive: '/images/tab/tab-person-active.png' }
    }
  }
}

const STORAGE_KEY = 'jc_icon_theme'

export const useIconThemeStore = defineStore('iconTheme', () => {
  // 默认使用 3D 宝石质感风
  const currentId = ref(localStorage.getItem(STORAGE_KEY) || 'gem')

  const current = computed(() => ICON_THEMES[currentId.value] || ICON_THEMES.gem)

  // 供后台展示的所有可选主题
  const themes = Object.values(ICON_THEMES)

  /**
   * 切换图标主题（后期管理后台调用）
   * @param {string} id - 主题 id，需在 ICON_THEMES 中存在
   */
  function setTheme(id) {
    if (!ICON_THEMES[id]) return false
    currentId.value = id
    localStorage.setItem(STORAGE_KEY, id)
    return true
  }

  /** 获取指定 Tab 在当前主题下的图标配置 */
  function getTabIcon(tabName) {
    return current.value.tabs[tabName] || null
  }

  return {
    currentId,
    current,
    themes,
    setTheme,
    getTabIcon
  }
})
