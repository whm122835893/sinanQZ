import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const shared = vi.hoisted(() => ({
  push: vi.fn(),
  get: vi.fn(),
  post: vi.fn()
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: shared.push }),
  useRoute: () => ({ fullPath: '/', params: {}, query: {} })
}))
vi.mock('vant', () => ({ showToast: vi.fn() }))
vi.mock('@/utils/request', () => ({
  // 用箭头间接一层：每个用例都会替换 shared.get，直接绑函数对象就固定住了第一个替身
  default: { get: (...a) => shared.get(...a), post: (...a) => shared.post(...a) }
}))
vi.mock('@/utils/loginGate', () => ({ useLoginGate: () => ({ requireLogin: () => true }) }))
vi.mock('@/stores/site', () => ({
  useSiteStore: () => ({ siteName: '', siteLogo: '', siteAvatar: '', resaleFeeRate: 5 })
}))
vi.mock('@/stores/iconTheme', () => ({
  useIconThemeStore: () => ({ getFeatureIcon: () => ({ type: 'img', icon: '' }) })
}))
vi.mock('@/stores/collection', () => ({
  useCollectionStore: () => ({
    featured: [],
    fetchFeatured: () => Promise.resolve(),
    getSaleStatus: () => 'selling',
    isFavorite: () => false,
    toggleFavorite: async () => true
  })
}))

import Home from '@/views/Home.vue'

/**
 * 首页轮播（views/Home.vue 的 hero 区）
 *
 * GET /api/banners 返回混合数组：后台开了「播」开关的推荐藏品排在最前，
 * 其后是「内容→轮播管理」里的普通轮播图。藏品条目带「推荐藏品」角标、点击跳市场里的寄售页，
 * 普通轮播图不可点；接口失败时退回本地三张兜底图。
 */
const bannerRow = (id) => ({ id, type: 'banner', image: `/uploads/banner-${id}.png`, description: '图', sortOrder: id })
const collectibleRow = (id) => ({
  id, type: 'collectible', collectibleId: id, image: `/uploads/cover-${id}.png`, description: '大绵羊', sortOrder: 0
})

function withBanners(list) {
  shared.get = vi.fn(async (url) => {
    if (url === '/banners') return list
    if (url === '/announcements') return { list: [] }
    if (url === '/raffle/activities') return { list: [] }
    return []
  })
  shared.push.mockClear()
}

async function mountHome() {
  const wrapper = mount(Home, {
    global: {
      stubs: {
        AppButton: { template: '<div><slot /></div>' },
        AppIcon: { template: '<div />' }
      }
    }
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.stubGlobal('requestAnimationFrame', (cb) => cb())
})

describe('首页轮播 · 推荐藏品条目', () => {
  it('推荐藏品排在最前，显示封面并带「推荐藏品」角标', async () => {
    withBanners([collectibleRow(7), bannerRow(1), bannerRow(2)])
    const wrapper = await mountHome()

    const items = wrapper.findAll('.home-hero__item')
    // 3 张 + 末尾复制的首图（无缝向左循环），共 4 个条目
    expect(items).toHaveLength(4)
    expect(items[0].find('img').attributes('src')).toBe('/uploads/cover-7.png')
    expect(items[1].find('img').attributes('src')).toBe('/uploads/banner-1.png')

    const badges = wrapper.findAll('.home-hero__badge')
    expect(badges).toHaveLength(2) // 首条 + 末尾副本
    expect(badges[0].text()).toBe('推荐藏品')
    expect(wrapper.findAll('.home-hero__dot')).toHaveLength(3)
  })

  it('点推荐藏品跳到市场里的寄售页，点普通轮播图不跳转', async () => {
    withBanners([collectibleRow(7), bannerRow(1)])
    const wrapper = await mountHome()

    await wrapper.findAll('.home-hero__item')[0].trigger('click')
    // 市场入口是 /resale/:id（与 MarketCard 同一个跳转），不是首发详情 /collection/:id
    expect(shared.push).toHaveBeenCalledWith('/resale/7')

    shared.push.mockClear()
    await wrapper.findAll('.home-hero__item')[1].trigger('click')
    expect(shared.push).not.toHaveBeenCalled()
  })

  it('只有一张推荐藏品时不轮播：无副本、无指示点，角标仍在', async () => {
    withBanners([collectibleRow(7)])
    const wrapper = await mountHome()

    expect(wrapper.findAll('.home-hero__item')).toHaveLength(1)
    expect(wrapper.findAll('.home-hero__dot')).toHaveLength(0)
    expect(wrapper.findAll('.home-hero__badge')).toHaveLength(1)
  })

  it('接口失败时保留本地兜底图，不带角标也不可点', async () => {
    shared.get = vi.fn(async () => { throw new Error('network down') })
    shared.push.mockClear()
    const wrapper = await mountHome()

    const items = wrapper.findAll('.home-hero__item')
    expect(items).toHaveLength(4) // 3 张本地兜底 + 末尾副本
    expect(wrapper.findAll('.home-hero__badge')).toHaveLength(0)

    await items[0].trigger('click')
    expect(shared.push).not.toHaveBeenCalled()
  })
})
