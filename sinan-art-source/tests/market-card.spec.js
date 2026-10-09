import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const shared = vi.hoisted(() => ({
  push: vi.fn(),
  showToast: vi.fn(),
  get: vi.fn(),
  post: vi.fn()
}))

vi.mock('vue-router', () => ({ useRouter: () => ({ push: shared.push }) }))
vi.mock('vant', () => ({ showToast: shared.showToast }))
vi.mock('@/utils/request', () => ({ default: { get: shared.get, post: shared.post } }))
vi.mock('@/stores/site', () => ({ useSiteStore: () => ({ purchaseLimitPerUser: 5 }) }))

import MarketCard from '@/components/MarketCard.vue'
import ResaleItem from '@/components/ResaleItem.vue'

/**
 * 市场卡片 / 寄售条目（components/MarketCard.vue、ResaleItem.vue）
 *
 * 关注按钮走 store.toggleFavorite（真实接口 POST /collections/:id/favorite），
 * 该 action 是 async：漏掉 await 时拿到的是 Promise（恒为真），
 * 取消关注也会提示「已关注」，且接口失败的错误被静默丢弃。
 */
const item = {
  id: '9696',
  name: '大绵羊',
  coverImage: '/uploads/a.png',
  price: '99.90',
  listingCount: 2,
  issueCount: '100',
  circulationCount: '40'
}

// 后台开了寄售开关、但目前还没有藏主挂单（后端 price=null / ordersCount=0）
const noListingItem = { ...item, price: '', listingCount: 0 }

async function mountFavCard(component, className) {
  const wrapper = mount(component, { props: { item } })
  await wrapper.find(className).trigger('click')
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.values(shared).forEach((fn) => fn.mockReset())
  shared.post.mockResolvedValue({ favorite: true })
})

describe('MarketCard', () => {
  it('展示藏品名、发行/流通量与地板价', () => {
    const wrapper = mount(MarketCard, { props: { item } })
    expect(wrapper.find('.market-card__name').text()).toBe('大绵羊')
    expect(wrapper.find('.market-card__meta').text()).toContain('发行 100')
    expect(wrapper.find('.market-card__meta').text()).toContain('流通 40')
    expect(wrapper.find('.market-card__price').text()).toBe('¥99.90')
  })

  // 2026-10-09：市场展示门槛改为后台「寄售开关」，开了开关但没人挂单的藏品也会进列表，
  // 此时不能显示 ¥（空价格），要显示「暂无寄售」
  it('无在售挂单时不显示地板价，改显示「暂无寄售」', () => {
    const wrapper = mount(MarketCard, { props: { item: noListingItem } })
    expect(wrapper.find('.market-card__price').exists()).toBe(false)
    expect(wrapper.find('.market-card__floor').exists()).toBe(false)
    expect(wrapper.find('.market-card__none').text()).toBe('暂无寄售')
  })

  it('无挂单卡片依然能点进寄售详情', async () => {
    const wrapper = mount(MarketCard, { props: { item: noListingItem } })
    await wrapper.find('.market-card').trigger('click')
    expect(shared.push).toHaveBeenCalledWith('/resale/9696')
  })

  it('点击卡片进入寄售详情', async () => {
    const wrapper = mount(MarketCard, { props: { item } })
    await wrapper.find('.market-card').trigger('click')
    expect(shared.push).toHaveBeenCalledWith('/resale/9696')
  })

  it('关注按钮按接口结果提示，并把关注态画到心形上', async () => {
    const wrapper = await mountFavCard(MarketCard, '.market-card__fav')
    expect(shared.post).toHaveBeenCalledWith('/collections/9696/favorite', { favorite: true })
    expect(shared.showToast).toHaveBeenCalledWith('已关注')
    expect(wrapper.find('.market-card__fav').classes()).toContain('active')
  })

  it('再次点击是取消关注，提示必须跟着翻转', async () => {
    const wrapper = mount(MarketCard, { props: { item } })
    await wrapper.find('.market-card__fav').trigger('click')
    await flushPromises()

    shared.post.mockResolvedValue({ favorite: false })
    await wrapper.find('.market-card__fav').trigger('click')
    await flushPromises()

    expect(shared.post).toHaveBeenLastCalledWith('/collections/9696/favorite', { favorite: false })
    expect(shared.showToast).toHaveBeenCalledWith('已取消关注')
    expect(wrapper.find('.market-card__fav').classes()).not.toContain('active')
  })

  it('关注接口失败不留下错误状态，只提示重试', async () => {
    shared.post.mockRejectedValue(new Error('网络异常'))
    const wrapper = mount(MarketCard, { props: { item } })
    await wrapper.find('.market-card__fav').trigger('click')
    await flushPromises()

    expect(shared.showToast).toHaveBeenCalledWith('操作失败，请稍后重试')
    expect(wrapper.find('.market-card__fav').classes()).not.toContain('active')
  })
})

describe('ResaleItem', () => {
  it('列表视图同样要处理无挂单：出「暂无寄售」而不是 ¥', () => {
    const wrapper = mount(ResaleItem, { props: { item: noListingItem } })
    expect(wrapper.find('.resale-item__price').exists()).toBe(false)
    expect(wrapper.find('.resale-item__none').text()).toBe('暂无寄售')
  })

  it('取消关注同样要 await，提示与图标同步翻转', async () => {
    const wrapper = await mountFavCard(ResaleItem, '.resale-item__fav')
    expect(shared.showToast).toHaveBeenCalledWith('已关注')

    shared.post.mockResolvedValue({ favorite: false })
    await wrapper.find('.resale-item__fav').trigger('click')
    await flushPromises()

    expect(shared.showToast).toHaveBeenLastCalledWith('已取消关注')
    expect(wrapper.find('.resale-item__fav').classes()).not.toContain('active')
  })
})
