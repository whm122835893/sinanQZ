import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

// ============================================================
// 图标主题系统（Theme）—— 可扩展，供后期管理员后台切换
//
// 设计目标：
//   1. 将「图标风格」从组件中解耦，以主题包形式集中管理
//   2. 每个主题包定义两类图标资源：
//      a) tabs      —— 底部导航栏 4 个 Tab 图标（SVG 矢量名 / 位图路径）
//      b) features  —— 页面功能入口图标（日历/活动/抽奖/库存/钱包等）
//   3. 当前激活主题持久化到 localStorage，刷新不丢失
//   4. 预留 setTheme() 方法，后期接入管理后台即可一键切换全站风格
//
// 新增风格只需在 ICON_THEMES 中追加一个条目即可，无需改组件代码。
// 若某风格暂未生成功能图标位图，features 可省略，自动回退到经典 SVG。
// ============================================================

/**
 * 图标主题包定义
 * @typedef {Object} IconTheme
 * @property {string}  id       - 主题唯一标识
 * @property {string}  name     - 主题显示名称（供后台展示）
 * @property {string}  type     - 渲染方式：'svg' 矢量 | 'image' 位图
 * @property {Object}  tabs     - 各 Tab 的图标配置
 * @property {Object}  features - 功能入口图标配置（可选，缺省回退 SVG）
 *
 * tabs 条目：{ icon?: string } 或 { image?: string, imageActive?: string }
 * features 条目：{ type: 'svg', icon: string } 或 { type: 'image', image: string }
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
    },
    features: {
      calendar:   { type: 'image', image: '/images/tab/modern-calendar.png' },
      activity:   { type: 'image', image: '/images/tab/modern-activity.png' },
      lottery:    { type: 'image', image: '/images/tab/modern-lottery.png' },
      inventory:  { type: 'image', image: '/images/tab/modern-inventory.png' },
      wallet:     { type: 'image', image: '/images/tab/modern-wallet.png' },
      invite:     { type: 'svg',   icon: 'invite' }
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
    },
    // 3D宝石质感功能图标
    features: {
      calendar:   { type: 'image', image: '/images/tab/gem-calendar.png' },
      activity:   { type: 'image', image: '/images/tab/gem-activity.png' },
      lottery:    { type: 'image', image: '/images/tab/gem-lottery.png' },
      inventory:  { type: 'image', image: '/images/tab/gem-cube.png' },
      wallet:     { type: 'image', image: '/images/tab/gem-wallet.png' }
    }
  },

  // ---- 新中式水墨风（AI 生成位图，水墨笔触+朱砂红印章）----
  ink: {
    id: 'ink',
    name: '新中式水墨',
    type: 'image',
    tabs: {
      home:   { image: '/images/tab/tab-home-ink.png',         imageActive: '/images/tab/tab-home-ink-active.png' },
      market: { image: '/images/tab/tab-bag-ink.png',           imageActive: '/images/tab/tab-bag-ink-active.png' },
      notice: { image: '/images/tab/tab-bell-ink.png',          imageActive: '/images/tab/tab-bell-ink-active.png' },
      user:   { image: '/images/tab/tab-person-ink.png',        imageActive: '/images/tab/tab-person-ink-active.png' }
    },
    // 新中式水墨功能图标
    features: {
      calendar:   { type: 'image', image: '/images/tab/ink-calendar.png' },
      activity:   { type: 'image', image: '/images/tab/ink-activity.png' },
      lottery:    { type: 'image', image: '/images/tab/ink-lottery.png' },
      inventory:  { type: 'image', image: '/images/tab/ink-cube.png' },
      wallet:     { type: 'image', image: '/images/tab/ink-wallet.png' }
    }
  },

  // ---- 青绿山水风（AI 生成位图，石青石绿矿物色+鎏金工笔）----
  shanse: {
    id: 'shanse',
    name: '青绿山水',
    type: 'image',
    tabs: {
      home:   { image: '/images/tab/tab-home-shanse.png',         imageActive: '/images/tab/tab-home-shanse-active.png' },
      market: { image: '/images/tab/tab-bag-shanse.png',           imageActive: '/images/tab/tab-bag-shanse-active.png' },
      notice: { image: '/images/tab/tab-bell-shanse.png',          imageActive: '/images/tab/tab-bell-shanse-active.png' },
      user:   { image: '/images/tab/tab-person-shanse.png',        imageActive: '/images/tab/tab-person-shanse-active.png' }
    },
    // 青绿山水功能图标
    features: {
      calendar:   { type: 'image', image: '/images/tab/shanse-calendar.png' },
      activity:   { type: 'image', image: '/images/tab/shanse-activity.png' },
      lottery:    { type: 'image', image: '/images/tab/shanse-lottery.png' },
      inventory:  { type: 'image', image: '/images/tab/shanse-cube.png' },
      wallet:     { type: 'image', image: '/images/tab/shanse-wallet.png' }
    }
  },

  // ---- 玻璃拟态风（AI 生成位图，半透明磨砂玻璃+彩色光晕折射）----
  glass: {
    id: 'glass',
    name: '玻璃拟态',
    type: 'image',
    tabs: {
      home:   { image: '/images/tab/tab-home-glass.png',         imageActive: '/images/tab/tab-home-glass-active.png' },
      market: { image: '/images/tab/tab-bag-glass.png',           imageActive: '/images/tab/tab-bag-glass-active.png' },
      notice: { image: '/images/tab/tab-bell-glass.png',          imageActive: '/images/tab/tab-bell-glass-active.png' },
      user:   { image: '/images/tab/tab-person-glass.png',        imageActive: '/images/tab/tab-person-glass-active.png' }
    },
    // 液态玻璃功能图标
    features: {
      calendar:   { type: 'image', image: '/images/tab/lg-calendar.png' },
      activity:   { type: 'image', image: '/images/tab/lg-activity.png' },
      lottery:    { type: 'image', image: '/images/tab/lg-lottery.png' },
      inventory:  { type: 'image', image: '/images/tab/lg-cube.png' },
      wallet:     { type: 'image', image: '/images/tab/lg-wallet.png' }
    }
  },

  // ---- 自定义图标（管理员后台上传位图，运行时由 setCustomIcons 动态填充）----
  custom: {
    id: 'custom',
    name: '自定义图标',
    type: 'image',
    tabs: {},
    features: {}
  }
}

const STORAGE_KEY_FEATURE = 'jc_icon_theme_feature'
const STORAGE_KEY_TAB = 'jc_icon_theme_tab'

export const useIconThemeStore = defineStore('iconTheme', () => {
  // 功能图标主题与底部导航主题相互独立，可分别切换（如：功能水墨 + 导航 SVG）
  const featureThemeId = ref(localStorage.getItem(STORAGE_KEY_FEATURE) || 'classic')
  const tabThemeId = ref(localStorage.getItem(STORAGE_KEY_TAB) || 'classic')

  // 自定义图标（管理员上传的功能入口位图），由 site.js init 从后端配置注入
  const customIcons = ref({
    calendar: '',
    activity: '',
    lottery: '',
    inventory: '',
    wallet: '',
    invite: ''
  })

  const featureTheme = computed(() => ICON_THEMES[featureThemeId.value] || ICON_THEMES.classic)
  const tabTheme = computed(() => ICON_THEMES[tabThemeId.value] || ICON_THEMES.classic)

  // 供后台展示的所有可选主题
  const themes = Object.values(ICON_THEMES)

  /**
   * 切换功能图标主题（日历/活动/抽奖/库存/钱包/邀请好友）
   * @param {string} id - 主题 id，需在 ICON_THEMES 中存在
   */
  function setFeatureTheme(id) {
    if (!ICON_THEMES[id]) return false
    featureThemeId.value = id
    localStorage.setItem(STORAGE_KEY_FEATURE, id)
    return true
  }

  /**
   * 切换底部导航栏图标主题
   * @param {string} id - 主题 id，需在 ICON_THEMES 中存在
   */
  function setTabTheme(id) {
    if (!ICON_THEMES[id]) return false
    tabThemeId.value = id
    localStorage.setItem(STORAGE_KEY_TAB, id)
    return true
  }

  /**
   * 一键同时切换功能与导航图标（向后兼容旧调用）
   * @param {string} id - 主题 id
   */
  function setTheme(id) {
    const ok = setFeatureTheme(id)
    return setTabTheme(id) && ok
  }

  /**
   * 注入管理员上传的自定义图标（URL 映射）
   * @param {Object} map - { calendar, activity, lottery, inventory, wallet, invite }
   */
  function setCustomIcons(map) {
    if (!map || typeof map !== 'object') return
    customIcons.value = { ...customIcons.value, ...map }
  }

  /** 获取指定 Tab 在底部导航主题下的图标配置 */
  function getTabIcon(tabName) {
    return tabTheme.value.tabs[tabName] || null
  }

  /**
   * 获取指定功能入口在功能图标主题下的图标配置
   * 若当前主题未定义 features，自动回退到 classic SVG
   * @param {string} featureName - calendar | activity | lottery | inventory | wallet | invite
   * @returns {{ type: 'svg', icon: string } | { type: 'image', image: string } | null}
   */
  function getFeatureIcon(featureName) {
    // 自定义主题：优先使用管理员上传的位图，缺省回退经典 SVG
    if (featureThemeId.value === 'custom') {
      const url = customIcons.value?.[featureName]
      if (url) return { type: 'image', image: url }
      return ICON_THEMES.classic.features[featureName] || null
    }
    const features = featureTheme.value.features
    if (features && features[featureName]) {
      return features[featureName]
    }
    // 回退到经典矢量图标
    return ICON_THEMES.classic.features[featureName] || null
  }

  return {
    featureThemeId,
    tabThemeId,
    featureTheme,
    tabTheme,
    themes,
    setFeatureTheme,
    setTabTheme,
    setTheme,
    setCustomIcons,
    getTabIcon,
    getFeatureIcon
  }
})
