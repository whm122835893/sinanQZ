import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive, h } from 'vue'

// 关注态（2026-10-09 修复「刷新一次关注就没了」）
// 口径：关注全集由 GET /user/favorites 说了算；市场列表只做增量补齐。
// 反过来拿市场列表当全集去删，就会把未开寄售开关 / 不在本页的藏品误抹掉。
const shared = vi.hoisted(() => ({
  push: vi.fn(),
  showToast: vi.fn(),
  startPoll: vi.fn(),
  stopPoll: vi.fn(),
  get: async () => ({}),
  post: async () => ({})
}))

vi.mock('vue-router', async () => {
  const { h } = await import('vue')
  return {
    useRoute: () => globalThis.__route,
    useRouter: () => ({ push: shared.push }),
    RouterView: { setup(_, { slots }) { return () => slots.default?.({ Component: null }) || h('div') } },
    RouterLink: { template: '<a><slot /></a>' },
    // App.vue 的子组件（登录弹窗等）会 import @/router → 需要造一个空壳路由
    createRouter: () => ({
      beforeEach: () => {},
      afterEach: () => {},
      push: (...a) => shared.push(...a),
      replace: () => {},
      resolve: (to) => ({ href: String(to) }),
      currentRoute: globalThis.__route
    }),
    createWebHashHistory: () => ({}),
    RouterLinkStub: { template: '<a><slot /></a>' }
  }
})
vi.mock('vant', () => ({ showToast: (...a) => shared.showToast(...a) }))
// 转发一层（箭头间接调用）：beforeEach 里会整体替换 shared.get/post，
// 直接把 fn 对象传进工厂会把 mock 固化在首次求值的那一个函数上。
vi.mock('@/utils/request', () => ({
  default: { get: (...a) => shared.get(...a), post: (...a) => shared.post(...a) }
}))
vi.mock('@/stores/site', () => ({
  useSiteStore: () => ({ purchaseLimitPerUser: 5, marketRecommendTabEnabled: false, shouldShowSplash: false })
}))
vi.mock('@/stores/user', () => ({ useUserStore: () => globalThis.__user }))
vi.mock('@/stores/inbox', () => ({
  useInboxStore: () => ({ pending: [], startPoll: shared.startPoll, stopPoll: shared.stopPoll })
}))

import MarketFollowing from '@/views/market/MarketFollowing.vue'
import App from '@/App.vue'
import { useCollectionStore } from '@/stores/collection'

/** GET /user/favorites 的精简行（id/name/image/price/issueCount/circulationCount/marketType） */
const favRow = (over = {}) => ({
  id: 42, name: '未开寄售的藏品', image: '/f.png', price: 30,
  issueCount: 100, circulationCount: 10, marketType: 'activity', ...over
})

/** 市场列表行：price=null + ordersCount=0 表示寄售开关开着但无人挂单 */
const marketRow = (over = {}) => ({
  id: 7, name: '大绵羊', image: '/m.png', price: 99, issuePrice: 60, ordersCount: 2,
  issueCount: 1000, circulationCount: 300, todayCount: 0,
  resalePriceMin: 0, resalePriceMax: 0, isFavorite: false,
  marketType: 'activity', recommended: false, ...over
})

/** GET 按 url 分流：市场列表 + 关注全集（favorites=null 表示未登录，接口会抛 401） */
function routes({ market = [], favorites = null } = {}) {
  shared.get = vi.fn(async (url) => {
    if (url === '/market/collections') return { list: market, total: market.length, page: 1, pageSize: 50 }
    if (url === '/user/favorites') {
      if (favorites === null) throw new Error('401 未登录')
      return { list: favorites, total: favorites.length, page: 1, pageSize: 100 }
    }
    if (url === '/collections/categories') return []
    return {}
  })
}

const calledUrls = () => shared.get.mock.calls.map(([url]) => url)

beforeEach(() => {
  setActivePinia(createPinia())
  localStorage.clear()
  globalThis.__route = reactive({
    name: 'market-following',
    path: '/market/following',
    fullPath: '/market/following',
    meta: { tabbar: true }
  })
  globalThis.__user = reactive({ isLoggedIn: false })
  shared.showToast.mockClear()
  shared.startPoll.mockClear()
  shared.stopPoll.mockClear()
  routes()
})

describe('关注态 · 权威来源是 /user/favorites', () => {
  it('未登录时不打 /user/favorites（避免 401 噪声）', async () => {
    const store = useCollectionStore()
    await store.fetchFavorites()
    expect(calledUrls()).not.toContain('/user/favorites')
    expect(store.favorites).toEqual([])
  })

  it('登录后拉到关注全集，市场里没有的藏品也算已关注', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [favRow()] })
    const store = useCollectionStore()
    await store.fetchFavorites()
    expect(store.isFavorite(42)).toBe(true)
    expect(store.favoriteRows[0]).toMatchObject({ id: '42', coverImage: '/f.png', isFavorite: true })
  })

  it('刷新后关注不丢：市场列表里没有的已关注 id 不会被抹掉（只增不删）', async () => {
    localStorage.setItem('jc_token', 't')
    // 藏品 42 后台寄售开关是关的 → 永远不出现在市场列表里，这正是旧版丢失的场景
    routes({
      favorites: [favRow()],
      market: [marketRow({ id: 7, isFavorite: false }), marketRow({ id: 9, isFavorite: true })]
    })
    const store = useCollectionStore()
    await store.fetchFavorites()
    await store.fetchMarket()
    expect(store.favorites).toEqual(expect.arrayContaining(['42', '9']))
    expect(store.isFavorite(42)).toBe(true)
    expect(store.isFavorite(7)).toBe(false)
  })

  it('市场行的 isFavorite 增量补齐本地关注态', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [], market: [marketRow({ id: 9, isFavorite: true })] })
    const store = useCollectionStore()
    await store.fetchMarket()
    expect(store.isFavorite(9)).toBe(true)
  })

  it('登出清空关注态，不给下一个账号留脏数据', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [favRow()] })
    const store = useCollectionStore()
    await store.fetchFavorites()
    store.clearFavorites()
    expect(store.favorites).toEqual([])
    expect(store.favoriteRows).toEqual([])
    expect(store.followedCollections).toEqual([])
  })

  it('回源失败保留现有关注态，不把心形刷没', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [favRow()] })
    const store = useCollectionStore()
    await store.fetchFavorites()
    shared.get = vi.fn(async () => { throw new Error('网络中断') })
    await store.fetchFavorites()
    expect(store.isFavorite(42)).toBe(true)
    expect(store.favoriteRows).toHaveLength(1)
  })
})

describe('toggleFavorite · 接口成功才改本地态', () => {
  it('关注成功：本地立即点亮心形，并回源补全「我的关注」', async () => {
    localStorage.setItem('jc_token', 't')
    // 用一个"活的"后端关注表模拟落库：POST 成功后 /user/favorites 才返回这一行
    const rows = []
    shared.get = vi.fn(async (url) => {
      if (url === '/user/favorites') return { list: rows, total: rows.length, page: 1, pageSize: 100 }
      return {}
    })
    shared.post = vi.fn(async (url, body) => {
      if (body.favorite) rows.push(favRow({ id: 42 }))
      else rows.splice(0, rows.length)
      return { isFavorite: body.favorite }
    })
    const store = useCollectionStore()
    const on = await store.toggleFavorite(42)
    expect(on).toBe(true)
    expect(shared.post.mock.calls[0][0]).toBe('/collections/42/favorite')
    expect(shared.post.mock.calls[0][1]).toEqual({ favorite: true })
    await flushPromises()
    expect(store.isFavorite(42)).toBe(true)
    expect(store.favoriteRows.map((f) => f.id)).toEqual(['42'])
    expect(calledUrls()).toContain('/user/favorites')
  })

  it('接口失败：抛出错误且心形保持原样', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [] })
    const store = useCollectionStore()
    shared.post = vi.fn(async () => { throw new Error('500') })
    await expect(store.toggleFavorite(42)).rejects.toThrow('500')
    expect(store.isFavorite(42)).toBe(false)
  })

  it('取消关注：从关注态和「我的关注」列表里同时移除', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [favRow()] })
    const store = useCollectionStore()
    await store.fetchFavorites()
    shared.post = vi.fn(async () => ({ isFavorite: false }))
    const on = await store.toggleFavorite(42)
    expect(on).toBe(false)
    expect(shared.post.mock.calls[0][1]).toEqual({ favorite: false })
    expect(store.isFavorite(42)).toBe(false)
    expect(store.followedCollections).toEqual([])
  })
})

describe('followedCollections · 我的关注列表', () => {
  it('市场里查不到的关注项也出现，无挂单标记齐全（卡片显示「暂无寄售」）', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [favRow()], market: [] })
    const store = useCollectionStore()
    await store.fetchFavorites()
    const [row] = store.followedCollections
    expect(row).toMatchObject({ id: '42', price: '', listingCount: 0, issuePrice: '30.00', isFavorite: true })
  })

  it('同一藏品优先用市场行（有挂单最低价），不重复出现', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [favRow({ id: 7 })], market: [marketRow({ id: 7, isFavorite: true })] })
    const store = useCollectionStore()
    await store.fetchFavorites()
    await store.fetchMarket()
    const list = store.followedCollections
    expect(list).toHaveLength(1)
    expect(list[0]).toMatchObject({ id: '7', price: '99.00', listingCount: 2 })
  })

  it('跟随市场的关键词筛选与价格排序', async () => {
    localStorage.setItem('jc_token', 't')
    routes({
      favorites: [favRow({ id: 1, name: '甲' }), favRow({ id: 2, name: '乙', price: 50 })],
      market: []
    })
    const store = useCollectionStore()
    await store.fetchFavorites()
    store.filters.keyword = '甲'
    expect(store.followedCollections.map((c) => c.name)).toEqual(['甲'])
    store.filters.keyword = ''
    store.marketSort = 'price-asc'
    expect(store.followedCollections.map((c) => c.name)).toEqual(['甲', '乙'])
    store.marketSort = 'price-desc'
    expect(store.followedCollections.map((c) => c.name)).toEqual(['乙', '甲'])
  })
})

describe('MarketFollowing.vue · 渲染关注全集', () => {
  async function mountFollowing() {
    const wrapper = mount(MarketFollowing)
    await flushPromises()
    return wrapper
  }

  it('关注了未开寄售开关的藏品，页面照样列出它的卡片', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [favRow()], market: [] })
    const store = useCollectionStore()
    await store.fetchFavorites()
    const wrapper = await mountFollowing()
    expect(wrapper.findAll('.market-card')).toHaveLength(1)
    expect(wrapper.text()).toContain('未开寄售的藏品')
    expect(wrapper.text()).not.toContain('暂无关注的藏品')
  })

  it('没有关注时显示空状态', async () => {
    const wrapper = await mountFollowing()
    expect(wrapper.findAll('.market-card')).toHaveLength(0)
    expect(wrapper.text()).toContain('暂无关注的藏品')
  })

  it('在「我的关注」里点心形取消关注：卡片当场消失', async () => {
    localStorage.setItem('jc_token', 't')
    routes({ favorites: [favRow()], market: [] })
    const store = useCollectionStore()
    await store.fetchFavorites()
    shared.post = vi.fn(async () => ({ isFavorite: false }))
    const wrapper = await mountFollowing()
    await wrapper.find('.market-card__fav').trigger('click')
    await flushPromises()
    expect(wrapper.findAll('.market-card')).toHaveLength(0)
    expect(wrapper.text()).toContain('暂无关注的藏品')
  })
})

describe('App.vue · 登录态变化时同步关注全集', () => {
  // script-setup 里的 <router-view> 标签走全局解析：测试没装路由插件，需显式给一个空壳
  const RouterViewShell = {
    setup(_, { slots }) {
      return () => slots.default?.({ Component: null }) || h('div')
    }
  }

  function mountApp() {
    return mount(App, {
      global: {
        components: {
          'router-view': RouterViewShell,
          'van-pull-refresh': { template: '<div><slot /></div>' },
          Splash: { template: '<div />' },
          AppTabBar: { template: '<div />' },
          AppLoginModal: { template: '<div />' },
          InboxPopup: { template: '<div />' }
        }
      },
      attachTo: document.body
    })
  }

  it('已登录挂载即拉关注全集并恢复心形；登出后清空', async () => {
    localStorage.setItem('jc_token', 't')
    globalThis.__user.isLoggedIn = true
    routes({ favorites: [favRow()] })
    const store = useCollectionStore()
    const wrapper = mountApp()
    await flushPromises()
    expect(calledUrls()).toContain('/user/favorites')
    expect(store.isFavorite(42)).toBe(true)
    expect(shared.startPoll).toHaveBeenCalled()

    globalThis.__user.isLoggedIn = false
    await flushPromises()
    expect(store.favorites).toEqual([])
    expect(shared.stopPoll).toHaveBeenCalled()
    wrapper.unmount()
  })

  it('未登录挂载不打关注接口', async () => {
    globalThis.__user.isLoggedIn = false
    const wrapper = mountApp()
    await flushPromises()
    expect(calledUrls()).not.toContain('/user/favorites')
    wrapper.unmount()
  })
})
