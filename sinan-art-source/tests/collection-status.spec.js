import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/utils/request', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@/stores/site', () => ({ useSiteStore: () => ({ purchaseLimitPerUser: 5 }) }))

import request from '@/utils/request'
import { useCollectionStore } from '@/stores/collection'
import { useUserStore } from '@/stores/user'

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

/**
 * 「寄售了一件，其余的看起来也全被锁」（2026-10-11 第一批修复）
 *
 * 口径：被锁定的是「具体某一件」资产实例（nft_user_collectibles 的一行），不是整个系列。
 * GET /api/user/collections 按藏品聚合，组级 isConsigned 只表示「这一系列里至少有一件在寄售」，
 * 逐件真相在 items[].isConsigned 上。早先前端拿组级标志 + nos 整组判定，才造成「锁一件、看着锁一排」；
 * 库里其实只锁了那一件（后端 UPDATE 的 WHERE 只有主键 id），所以这是显示误判，不是资产事故。
 */
describe('user store · 寄售锁定按资产实例判定', () => {
  let user

  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    request.get = vi.fn()
    request.post = vi.fn()
    user = useUserStore()
    user.setToken('test-token')
  })

  /** 组行载荷：3 件里只有 SN-3 挂单中 */
  const inventoryGroup = (over = {}) => ({
    id: 7, name: '洛神赋图', image: '/luoshen.png', price: '128', qty: 3,
    nos: ['SN-1', 'SN-2', 'SN-3'],
    isConsigned: true,
    userCollectibleIds: [101, 102, 103],
    items: [
      { userCollectibleId: 101, serial: 'SN-1', tokenId: '', txHash: '', isConsigned: false },
      { userCollectibleId: 102, serial: 'SN-2', tokenId: '', txHash: '', isConsigned: false },
      { userCollectibleId: 103, serial: 'SN-3', tokenId: '', txHash: '', isConsigned: true }
    ],
    acquiredAt: '2026-10-01 10:00:00', type: 'release', ...over
  })

  async function loadInventory(over = {}) {
    request.get = vi.fn().mockResolvedValue([inventoryGroup(over)])
    await user.fetchInventory()
  }

  it('同系列三件中只有真正挂单的那件被判为锁定', async () => {
    await loadInventory()

    expect(user.isNoLocked(7, 'SN-3')).toBe(true)
    // 修复前这两条都是 true（组级 isConsigned 一刀切）
    expect(user.isNoLocked(7, 'SN-1')).toBe(false)
    expect(user.isNoLocked(7, 'SN-2')).toBe(false)
  })

  it('lockedNos 取自逐件明细，和 nos 列表内容无关', async () => {
    const serials = Array.from({ length: 8 }, (_, i) => `SN-${i + 1}`)
    await loadInventory({
      qty: 8,
      nos: serials.slice(0, 5),
      items: serials.map((s, i) => ({
        userCollectibleId: 200 + i, serial: s, isConsigned: s === 'SN-7'
      }))
    })

    expect(user.inventory[0].lockedNos).toEqual(['SN-7'])
    expect(user.isNoLocked(7, 'SN-7')).toBe(true)
    expect(user.isNoLocked(7, 'SN-1')).toBe(false)
  })

  it('缺逐件明细（旧缓存）时退回 lockedNos，不把组级标志当整组锁', async () => {
    await loadInventory({ items: undefined })

    expect(user.inventory[0].isConsigned).toBe(true)
    expect(user.inventory[0].lockedNos).toEqual([])
    expect(user.isNoLocked(7, 'SN-1')).toBe(false)
  })

  it('id 传数字或字符串都要能匹配（库存里存的是字符串）', async () => {
    await loadInventory()

    expect(user.inventory[0].id).toBe('7')
    expect(user.isNoLocked(7, 'SN-3')).toBe(true)
    expect(user.isNoLocked('7', 'SN-3')).toBe(true)
  })

  it('未知藏品与空编号一律不锁，不抛异常', async () => {
    await loadInventory()

    expect(user.isNoLocked(999, 'SN-3')).toBe(false)
    expect(user.isNoLocked(7, '')).toBe(false)
    expect(user.isNoLocked(7, undefined)).toBe(false)
  })

  it('取消寄售成功后重新拉库存，该件随之解锁', async () => {
    await loadInventory()
    expect(user.isNoLocked(7, 'SN-3')).toBe(true)

    request.post = vi.fn().mockResolvedValue({})
    request.get = vi.fn().mockResolvedValue([inventoryGroup({
      isConsigned: false,
      items: [
        { userCollectibleId: 101, serial: 'SN-1', isConsigned: false },
        { userCollectibleId: 102, serial: 'SN-2', isConsigned: false },
        { userCollectibleId: 103, serial: 'SN-3', isConsigned: false }
      ]
    })])
    await user.cancelConsign(55)

    expect(request.post).toHaveBeenCalledWith('/resale/listings/55/cancel')
    expect(user.isNoLocked(7, 'SN-3')).toBe(false)
    expect(user.inventory[0].lockedNos).toEqual([])
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
