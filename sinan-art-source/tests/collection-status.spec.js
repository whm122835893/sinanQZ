import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/utils/request', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@/stores/site', () => ({ useSiteStore: () => ({ purchaseLimitPerUser: 5 }) }))

import request from '@/utils/request'
import { useCollectionStore } from '@/stores/collection'

/**
 * 首页发售区的状态判定（stores/collection.js）
 *
 * 这里的 toTs 会把后端 datetime(3) 的 'YYYY-MM-DD HH:mm:ss.000' 归一为时间戳，
 * 而 getSaleStatus 决定用户看到的是「倒计时 / 发售中 / 已售罄」——
 * 后端强制下架残留的过期结束时间曾让 C 端恒显已售罄，两侧必须一起钉住。
 */
describe('collection store · 发售状态', () => {
  let store

  beforeEach(() => {
    setActivePinia(createPinia())
    store = useCollectionStore()
    vi.useRealTimers()
    request.get = vi.fn()
  })

  const future = () => Date.now() + 3600_000
  const past = () => Date.now() - 3600_000

  it('未到开售时间显示倒计时，到点后转为发售中', () => {
    expect(store.getSaleStatus({ saleTime: future() })).toBe('countdown')
    expect(store.getSaleStatus({ saleTime: past() })).toBe('selling')
  })

  it('过完结束时间或无库存即售罄', () => {
    expect(store.getSaleStatus({ saleEndTime: past() })).toBe('soldout')
    expect(store.getSaleStatus({ soldOut: true })).toBe('soldout')
    expect(store.getSaleStatus({ stock: 0 })).toBe('soldout')
    expect(store.getSaleStatus(null)).toBe('soldout')
  })

  /** 后端强制下架会把 off_sale_at 写成下架那一刻；重新上架若不清空，这里会永久 soldout */
  it('结束时间为空表示不限期，不能被当成 1970 已过期', async () => {
    request.get = vi.fn().mockResolvedValue({
      list: [featuredPayload({ saleEndTime: null })],
      total: 1
    })
    await store.fetchFeatured()
    const item = store.featured[0]
    expect(item.saleEndTime).toBe(0)
    expect(store.getSaleStatus(item)).toBe('selling')
  })

  it('后端 datetime(3) 带毫秒的时间串要换算成正确时间戳', async () => {
    request.get = vi.fn().mockResolvedValue({
      list: [featuredPayload({ saleTime: '2030-11-11 20:00:00.000' })],
      total: 1
    })
    await store.fetchFeatured()
    const item = store.featured[0]
    expect(item.saleTime).toBe(new Date(2030, 10, 11, 20, 0, 0, 0).getTime())
    expect(store.getSaleStatus(item)).toBe('countdown')
  })

  it('featured 载荷规整：价格两位小数、库存为 0 判售罄', async () => {
    request.get = vi.fn().mockResolvedValue({
      list: [featuredPayload({ price: '99.9', saleType: 'blindbox', isBlindBox: true, stock: 0 })],
      total: 1
    })
    await store.fetchFeatured()
    const item = store.featured[0]
    expect(item.id).toBe('9696')
    expect(item.price).toBe('99.90')
    expect(item.tag).toBe('盲盒')
    expect(item.type).toBe('blindbox')
    expect(item.soldOut).toBe(true)
    expect(store.getSaleStatus(item)).toBe('soldout')
  })

  /** 角标取自 saleType，卡片类型取自 isBlindBox——两者是独立字段，混用会渲染错标签 */
  it('saleType 决定角标，isBlindBox 决定卡片类型', async () => {
    request.get = vi.fn().mockResolvedValue({
      list: [
        featuredPayload({ id: 1, saleType: 'priority', isBlindBox: false }),
        featuredPayload({ id: 2, saleType: 'normal', tag: '', isBlindBox: true })
      ],
      total: 2
    })
    await store.fetchFeatured()
    expect(store.featured[0].tag).toBe('优先购')
    expect(store.featured[0].type).toBe('release')
    expect(store.featured[1].tag).toBe('首发')
    expect(store.featured[1].type).toBe('blindbox')
  })
})

function featuredPayload(overrides = {}) {
  return {
    id: 9696,
    name: '测试藏品',
    tag: '首发',
    price: 99,
    edition: 100,
    image: '/uploads/a.jpg',
    saleTime: pastSaleTime(),
    saleEndTime: null,
    status: 'onsale',
    stock: 100,
    isBlindBox: false,
    saleType: 'normal',
    ...overrides
  }
}

function pastSaleTime() {
  return new Date(Date.now() - 7200_000)
    .toISOString()
    .slice(0, 19)
    .replace('T', ' ')
}
