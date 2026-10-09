import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'

// 顶部 tab（活动/自由）与「推荐」胶囊的行为钉在此处：
// 两个市场的数据源、以及推荐胶囊的显隐条件，都必须和后端 market_type /
// system_configs.market_recommend_tab_enabled 的口径一致。
const shared = vi.hoisted(() => ({
  push: vi.fn(),
  get: vi.fn(),
  post: vi.fn(),
  site: { marketRecommendTabEnabled: false, purchaseLimitPerUser: 5, loaded: true }
}))

vi.mock('vue-router', async () => {
  const { h } = await import('vue')
  return {
    useRoute: () => globalThis.__route,
    useRouter: () => ({ push: shared.push }),
    // 子视图在测试里不需要真实内容，提供 v-slot 的 Component 即可
    RouterView: { setup(_, { slots }) { return () => slots.default?.({ Component: null }) || h('div') } },
    RouterLink: { template: '<a><slot /></a>' }
  }
})
// 转发一层（而非直接传 shared.get）：beforeEach 里会整体替换 shared.get，
// 直接引用会把 mock 固化在首次求值的那一个函数上。
vi.mock('@/utils/request', () => ({
  default: { get: (...a) => shared.get(...a), post: (...a) => shared.post(...a) }
}))
vi.mock('@/stores/site', () => ({ useSiteStore: () => shared.site }))

import Market from '@/views/Market.vue'
import { useCollectionStore } from '@/stores/collection'

/** 取最近一次 /market/collections 请求的 query */
function marketQuery() {
  const call = [...shared.get.mock.calls].reverse()
    .find(([url]) => url === '/market/collections')
  return call ? call[1].params : null
}

function mountMarket(routeName = 'market-activity') {
  globalThis.__route = reactive({ name: routeName, path: `/market/${routeName}` })
  return mount(Market, { attachTo: document.body })
}

beforeEach(() => {
  setActivePinia(createPinia())
  shared.get = vi.fn(async (url) => {
    if (url === '/collections/categories') return []
    if (url === '/market/collections') return { list: [], total: 0, page: 1, pageSize: 50 }
    return {}
  })
  shared.get.mockClear()
  // 站点配置在真实应用里是 Pinia 响应式对象；Market.vue 要 watch 它的 loaded，
  // 所以测试里也必须给一个 reactive 的壳（普通对象 watch 不到）。
  shared.site = reactive({ marketRecommendTabEnabled: false, purchaseLimitPerUser: 5, loaded: true })
})

describe('collection store · 市场分栏', () => {
  it('默认拉活动市场，且不带推荐过滤', async () => {
    const store = useCollectionStore()
    await store.fetchMarket()
    expect(marketQuery()).toMatchObject({ marketType: 'activity', recommend: 0 })
  })

  it('切到自由市场会重新请求，并退出推荐态', async () => {
    const store = useCollectionStore()
    store.marketRecommend = true
    await store.setMarketType('free')
    expect(marketQuery()).toMatchObject({ marketType: 'free', recommend: 0 })
    expect(store.marketRecommend).toBe(false)
  })

  it('「我的关注」跨两个市场看（marketType=all）', async () => {
    const store = useCollectionStore()
    await store.setMarketType('all')
    expect(marketQuery()).toMatchObject({ marketType: 'all' })
  })

  it('推荐只在活动市场生效：自由市场下置为 true 也会被驳回', async () => {
    const store = useCollectionStore()
    await store.setMarketType('free')
    await store.setMarketRecommend(true)
    expect(store.marketRecommend).toBe(false)
    expect(marketQuery()).toMatchObject({ marketType: 'free', recommend: 0 })

    await store.setMarketType('activity')
    await store.setMarketRecommend(true)
    expect(store.marketRecommend).toBe(true)
    expect(marketQuery()).toMatchObject({ marketType: 'activity', recommend: 1 })
  })

  it('列表项透传 marketType / recommended', async () => {
    shared.get = vi.fn(async () => ({
      list: [{
        id: 9696, name: '大绵羊', image: '/i.png', price: 12,
        issueCount: 100, circulationCount: 10, todayCount: 1,
        resalePriceMin: 0, resalePriceMax: 0,
        marketType: 'activity', recommended: true
      }],
      total: 1
    }))
    const store = useCollectionStore()
    await store.fetchMarket()
    expect(store.marketCollections[0]).toMatchObject({ marketType: 'activity', recommended: true })
  })
})

describe('市场展示门槛 · 寄售开关开就进市场（2026-10-09 口径）', () => {
  // 后端 GET /api/market/collections 单条：price=在售挂单最低价（无挂单为 null），
  // issuePrice=发售价，ordersCount=在售挂单数
  const row = (over = {}) => ({
    id: 1, name: '大绵羊', image: '/i.png',
    price: 99, issuePrice: 99, ordersCount: 3,
    issueCount: 1000, circulationCount: 0, todayCount: 0,
    resalePriceMin: 0, resalePriceMax: 0,
    isFavorite: false, marketType: 'activity', recommended: false,
    ...over
  })

  async function withRows(list) {
    shared.get = vi.fn(async (url) =>
      url === '/market/collections' ? { list, total: list.length, page: 1, pageSize: 50 } : [])
    const store = useCollectionStore()
    await store.fetchMarket()
    return store
  }

  it('有在售挂单：price 用挂单最低价，listingCount 透传', async () => {
    const store = await withRows([row({ price: 88.5, ordersCount: 2 })])
    expect(store.marketCollections[0]).toMatchObject({
      price: '88.50', listingCount: 2, issuePrice: '99.00'
    })
  })

  it('寄售开关开了但没人挂单：price 为空串、listingCount=0（卡片显示「暂无寄售」）', async () => {
    const store = await withRows([row({ price: null, ordersCount: 0 })])
    const item = store.marketCollections[0]
    expect(item).toMatchObject({ price: '', listingCount: 0, issuePrice: '99.00', orders: [] })
  })

  it('排序不拿空价格比较：无挂单藏品按发售价排，顺序稳定', async () => {
    const store = await withRows([
      row({ id: 1, name: '有挂单', price: 200, ordersCount: 1, issuePrice: 50 }),
      row({ id: 2, name: '无挂单', price: null, ordersCount: 0, issuePrice: 99 })
    ])
    // 升序：有挂单 200 vs 无挂单按发售价 99 → 无挂单在前（后端 COALESCE 同口径）
    expect(store.sortedMarketCollections.map((c) => c.name)).toEqual(['无挂单', '有挂单'])
    store.marketSort = 'price-desc'
    expect(store.sortedMarketCollections.map((c) => c.name)).toEqual(['有挂单', '无挂单'])
  })
})

describe('市场页 · 推荐胶囊', () => {
  it('后台开关关闭时不显示推荐胶囊', async () => {
    const wrapper = mountMarket()
    await flushPromises()
    const pills = wrapper.findAll('.market-sub__cat').map((n) => n.text())
    expect(pills[0]).toBe('全部')
    expect(pills).not.toContain('推荐')
  })

  it('开关打开且在活动市场时，推荐胶囊排在「全部」左侧', async () => {
    shared.site.marketRecommendTabEnabled = true
    const wrapper = mountMarket()
    await flushPromises()
    const pills = wrapper.findAll('.market-sub__cat').map((n) => n.text())
    expect(pills[0]).toBe('推荐')
    expect(pills[1]).toBe('全部')
  })

  it('切到自由市场：推荐胶囊消失，并按 free 重新拉取', async () => {
    shared.site.marketRecommendTabEnabled = true
    const wrapper = mountMarket()
    await flushPromises()
    globalThis.__route.name = 'market-free'
    await flushPromises()
    expect(wrapper.findAll('.market-sub__cat').map((n) => n.text())).not.toContain('推荐')
    expect(marketQuery()).toMatchObject({ marketType: 'free' })
  })

  it('点推荐只拉推荐藏品；再点分类回到该分类并取消推荐态', async () => {
    shared.site.marketRecommendTabEnabled = true
    const wrapper = mountMarket()
    await flushPromises()
    const pills = wrapper.findAll('.market-sub__cat')
    await pills[0].trigger('click')
    await flushPromises()
    expect(marketQuery()).toMatchObject({ recommend: 1 })

    // 胶囊顺序：推荐 / 全部 / 水墨 / 国潮
    await pills[2].trigger('click')
    await flushPromises()
    expect(marketQuery()).toMatchObject({ recommend: 0, category: 'ink' })
  })
})

describe('进市场的默认落点 · 开关开就默认停在「推荐」（2026-10-10）', () => {
  const rec = (id, name) => ({
    id, name, image: '/i.png', price: 100, issuePrice: 100, ordersCount: 1,
    issueCount: 10, circulationCount: 5, todayCount: 0,
    resalePriceMin: 0, resalePriceMax: 0, isFavorite: false,
    marketType: 'activity', recommended: true
  })

  /** 按 recommend 入参分流的假接口：推荐位有货 / 没货两种情形 */
  function recommendRoutes({ recommended = [], all = [] } = {}) {
    shared.get = vi.fn(async (url, cfg) => {
      if (url === '/collections/categories') return []
      if (url === '/market/collections') {
        const list = cfg.params.recommend === 1 ? recommended : all
        return { list, total: list.length, page: 1, pageSize: 50 }
      }
      return {}
    })
  }

  /** 每次 /market/collections 请求带的 recommend 值（按先后顺序） */
  function recommendSeq() {
    return shared.get.mock.calls
      .filter(([url]) => url === '/market/collections')
      .map(([, cfg]) => cfg.params.recommend)
  }

  const activePill = (wrapper) =>
    wrapper.findAll('.market-sub__cat').find((n) => n.classes().includes('active'))?.text()

  it('开关开 + 有推荐藏品：默认停在推荐，只发一次 recommend=1 的请求', async () => {
    shared.site.marketRecommendTabEnabled = true
    recommendRoutes({ recommended: [rec(1, '甲')] })
    const store = useCollectionStore()
    await store.setMarketType('activity')
    expect(recommendSeq()).toEqual([1])
    expect(store.marketRecommend).toBe(true)
  })

  it('开关开 + 一件推荐都没有：自动退回「全部」，第二次请求 recommend=0', async () => {
    shared.site.marketRecommendTabEnabled = true
    recommendRoutes({ recommended: [], all: [rec(2, '乙')] })
    const store = useCollectionStore()
    await store.setMarketType('activity')
    expect(recommendSeq()).toEqual([1, 0])
    expect(store.marketRecommend).toBe(false)
    expect(store.marketCollections.map((c) => c.name)).toEqual(['乙'])
  })

  it('开关关：默认全部，且不去试推荐（不浪费一次请求）', async () => {
    shared.site.marketRecommendTabEnabled = false
    recommendRoutes({ all: [rec(2, '乙')] })
    const store = useCollectionStore()
    await store.setMarketType('activity')
    expect(recommendSeq()).toEqual([0])
    expect(store.marketRecommend).toBe(false)
  })

  it('自由市场与「我的关注」不默认推荐（推荐只是活动市场的一枚分类）', async () => {
    shared.site.marketRecommendTabEnabled = true
    recommendRoutes({ recommended: [rec(1, '甲')] })
    const store = useCollectionStore()
    await store.setMarketType('free')
    expect(recommendSeq()).toEqual([0])
    await store.setMarketType('all')
    expect(recommendSeq()).toEqual([0, 0])
    expect(store.marketRecommend).toBe(false)
  })

  it('页面默认落点：进市场即高亮「推荐」胶囊，列表是推荐藏品', async () => {
    shared.site.marketRecommendTabEnabled = true
    recommendRoutes({ recommended: [rec(1, '甲')] })
    const wrapper = mountMarket()
    await flushPromises()
    expect(activePill(wrapper)).toBe('推荐')
    expect(useCollectionStore().marketCollections.map((c) => c.name)).toEqual(['甲'])
  })

  it('推荐位为空时页面高亮回到「全部」，不出现空列表', async () => {
    shared.site.marketRecommendTabEnabled = true
    recommendRoutes({ recommended: [], all: [rec(2, '乙')] })
    const wrapper = mountMarket()
    await flushPromises()
    expect(activePill(wrapper)).toBe('全部')
    expect(useCollectionStore().marketCollections.map((c) => c.name)).toEqual(['乙'])
  })

  it('站点配置晚于本页到达：先不发列表请求，等 loaded 翻转后按开关决定默认落点', async () => {
    shared.site.marketRecommendTabEnabled = false
    shared.site.loaded = false
    recommendRoutes({ recommended: [rec(1, '甲')] })
    const wrapper = mountMarket()
    await flushPromises()
    // 配置没到位就发请求，会把"开关其实开着"的市场错初始化成「全部」→ 先等一等
    expect(recommendSeq()).toEqual([])

    // /api/config 回来了：开关是开的 → 默认停在推荐
    shared.site.marketRecommendTabEnabled = true
    shared.site.loaded = true
    await flushPromises()
    expect(recommendSeq()).toEqual([1])
    expect(activePill(wrapper)).toBe('推荐')
  })
})
