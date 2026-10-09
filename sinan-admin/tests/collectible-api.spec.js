import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  del: vi.fn(),
  getSilent: vi.fn(),
  http: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() }
}))

vi.mock('@/utils/request', () => api)

import {
  getCollectibleList,
  getCollectibleDetail,
  saveCollectible,
  toggleCollectibleStatus,
  releaseCollectible,
  moveCollectibleMarket,
  toggleCollectibleMarketRecommend,
  toggleCollectibleHomeCarousel
} from '@/api'

/**
 * 藏品接口适配层（src/api/index.js）
 *
 * 后端 admin 端点输出 snake_case→驼峰后的原始列（status 'off'、datetime(3) 带毫秒、
 * 0/1 布尔、category_id 数字），视图却约定 'offline' / 布尔 / 分类名 / res.code===0。
 * 这层映射错一处，列表就会显示错状态或把空发售时间当成非法值，
 * 而它是纯函数式转换，最能也最该离线锁住。
 */
const ok = (data) => ({ code: 0, message: 'ok', data })

const backendRow = (overrides = {}) => ({
  id: 9696,
  name: '大绵羊',
  subtitle: null,
  image: '/uploads/a.png',
  categoryId: 2,
  categoryName: null,
  price: '99.90',
  edition: '100',
  circulate: '10',
  sold: '20',
  lockedQuantity: '1',
  reservedCount: '3',
  airdroppedCount: '0',
  destroyedCount: '0',
  status: 'onsale',
  tag: '首发',
  issuer: '司南',
  onsaleAt: '2026-09-01 10:00:00.000',
  description: null,
  featured: 1,
  isBlindBox: 0,
  availablePool: 79,
  isTransferable: 1,
  isResaleable: 0,
  resalePriceMode: 0,
  resalePriceMin: null,
  resalePriceMax: null,
  perUserLimit: 5,
  chainType: 'axiu',
  contract: '0x1',
  marketType: 'activity',
  isMarketRecommended: 0,
  isHomeCarouselRecommended: 0,
  ...overrides
})

beforeEach(() => {
  Object.values(api).forEach((fn) => fn?.mockReset?.())
  Object.values(api.http).forEach((fn) => fn.mockReset())
})

describe('adaptCollectible · 字段规整', () => {
  it('datetime(3) 毫秒要剪掉，空开售时间规整成空串', async () => {
    api.get.mockResolvedValue(ok({ list: [backendRow({ offSaleAt: '2026-09-30 22:00:00.000' })], total: 1 }))
    const { data } = await getCollectibleList({ page: 1 })
    expect(data.list[0].saleTime).toBe('2026-09-01 10:00:00')
    expect(data.list[0].onsaleAt).toBe('2026-09-01 10:00:00')
    expect(data.list[0].offSaleAt).toBe('2026-09-30 22:00:00')

    api.get.mockResolvedValue(ok({ list: [backendRow({ onsaleAt: null })], total: 1 }))
    const res = await getCollectibleList({ page: 1 })
    expect(res.data.list[0].saleTime).toBe('')
    expect(res.data.list[0].onsaleAt).toBe('')
    expect(res.data.list[0].offSaleAt).toBe('')
  })

  it('后端 off → 前端 offline，其余状态原样', async () => {
    api.get.mockResolvedValue(ok({
      list: [backendRow({ status: 'off' }), backendRow({ status: 'soldout' })],
      total: 2
    }))
    const { data } = await getCollectibleList({ page: 1 })
    expect(data.list.map((c) => c.status)).toEqual(['offline', 'soldout'])
  })

  it('查询状态反向映射：offline 要转成 off 再发给后端', async () => {
    api.get.mockResolvedValue(ok({ list: [], total: 0 }))
    await getCollectibleList({ page: 1, status: 'offline' })
    expect(api.get).toHaveBeenCalledWith('/collectibles', { page: 1, status: 'off' })
  })

  it('分类名优先取后端，缺失时按 ID 兜底，再兜不住才是未分类', async () => {
    api.get.mockResolvedValue(ok({
      list: [
        backendRow({ categoryName: '青铜', categoryId: 2 }),
        backendRow({ categoryName: null, categoryId: 3 }),
        backendRow({ categoryName: null, categoryId: 99 })
      ],
      total: 3
    }))
    const { data } = await getCollectibleList({ page: 1 })
    expect(data.list.map((c) => c.category)).toEqual(['青铜', '限定', '未分类'])
  })

  it('数值列转 number、0/1 转布尔，字符串价格不丢精度', async () => {
    api.get.mockResolvedValue(ok({ list: [backendRow()], total: 1 }))
    const item = (await getCollectibleList({ page: 1 })).data.list[0]
    expect(item.price).toBe(99.9)
    expect(item.edition).toBe(100)
    expect(item.sold).toBe(20)
    expect(item.subtitle).toBe('')
    expect(item.description).toBe('')
    expect(item.featured).toBe(true)
    expect(item.isBlindBox).toBe(false)
    expect(item.isTransferable).toBe(true)
    expect(item.isResaleable).toBe(false)
    // 寄售限价保留原值，前端需要区分「未设置」与 0
    expect(item.resalePriceMin).toBeNull()
  })

  it('非 0 码原样返回，不做适配（避免把错误响应当列表渲染）', async () => {
    const err = { code: 4003, message: '无权限', data: null }
    api.get.mockResolvedValue(err)
    await expect(getCollectibleList({ page: 1 })).resolves.toBe(err)
  })
})

describe('getCollectibleDetail · 平铺结构拍平', () => {
  it('库存审计、持有人、配额、销毁记录逐块规整', async () => {
    api.get.mockResolvedValue(ok({
      ...backendRow(),
      inventoryAudit: { identityOk: true, holdingOk: false, pool: 79, edition: 100, identityDesc: '恒等式成立', holdingDesc: '持仓不平' },
      holders: [{ userId: 12, nickname: '', serial: 'S-1', quantity: '2' }],
      quotas: [{ id: 5, quotaType: '1', quotaName: '优先购', plannedQuantity: '10', usedQuantity: '3', status: '1', activityType: null, remark: null }],
      destroyRecords: [{ id: 7, targetName: '盲盒', targetType: 'blindbox', quantity: '1', adminName: 'admin', createdAt: '2026-09-01 09:00:00', reason: '重复发放' }]
    }))
    const { data } = await getCollectibleDetail(9696)

    expect(data.id).toBe(9696)
    expect(data.saleTime).toBe('2026-09-01 10:00:00')
    expect(data.audit).toEqual({ ok: false, pool: 79, edition: 100, identityDesc: '恒等式成立', holdingDesc: '持仓不平' })
    expect(data.holders).toEqual([{ userId: 12, nickname: '用户12', serial: 'S-1', quantity: 2 }])
    expect(data.quotas[0]).toMatchObject({ quotaType: 1, plannedQuantity: 10, usedQuantity: 3, status: 1, activityType: '', remark: '' })
    expect(data.destroyRecords[0]).toMatchObject({ operator: 'admin', time: '2026-09-01 09:00:00', quantity: 1 })
    expect(data.qualification).toBeNull()
  })

  it('后端字段缺省时各列表为空数组而不是 undefined', async () => {
    api.get.mockResolvedValue(ok(backendRow()))
    const { data } = await getCollectibleDetail(9696)
    expect(data.holders).toEqual([])
    expect(data.quotas).toEqual([])
    expect(data.destroyRecords).toEqual([])
    expect(data.audit.ok).toBe(false)
  })
})

describe('saveCollectible · 载荷转换', () => {
  it('新建走 POST，发售时间留空必须显式传空串（后端据此立即开售）', async () => {
    api.post.mockResolvedValue(ok({ id: 1 }))
    await saveCollectible({ name: '测试', category: '国潮', price: '99.9', edition: 100, cover: '/a.png' })

    expect(api.post).toHaveBeenCalledTimes(1)
    const [url, body] = api.post.mock.calls[0]
    expect(url).toBe('/collectibles')
    expect(body.onsale_at).toBe('')
    expect(body.off_sale_at).toBe('')
    expect(body.release_date).toBe('')
    expect(body).toMatchObject({ name: '测试', category_id: 2, price: 99.9, edition: 100, image: '/a.png', featured: 0 })
    expect(body.id).toBeUndefined()
  })

  it('发售窗口三字段一起提交，开始时间同步到 release_date 兼容旧口径', async () => {
    api.post.mockResolvedValue(ok({ id: 1 }))
    await saveCollectible({
      name: '测试', cover: '/a.png',
      onsaleAt: '2026-11-11 20:00:00', offSaleAt: '2026-11-20 20:00:00'
    })
    const body = api.post.mock.calls[0][1]
    expect(body.onsale_at).toBe('2026-11-11 20:00:00')
    expect(body.off_sale_at).toBe('2026-11-20 20:00:00')
    expect(body.release_date).toBe('2026-11-11 20:00:00')
  })

  it('分类 ID 优先，名称只在无 ID 时查表，未知分类落到国潮', async () => {
    api.post.mockResolvedValue(ok({ id: 1 }))
    await saveCollectible({ name: 'a', categoryId: 3 })
    expect(api.post.mock.calls[0][1].category_id).toBe(3)

    await saveCollectible({ name: 'b', categoryId: 0, category: '水墨' })
    expect(api.post.mock.calls[1][1].category_id).toBe(1)

    await saveCollectible({ name: 'c', category: '青铜' })
    expect(api.post.mock.calls[2][1].category_id).toBe(2)
  })

  it('编辑走 PUT 并带上 id', async () => {
    api.put.mockResolvedValue(ok(null))
    await saveCollectible({ id: 9696, name: '测试', cover: '/a.png' })
    const [url, body] = api.put.mock.calls[0]
    expect(url).toBe('/collectibles/9696')
    expect(body.id).toBe(9696)
    expect(api.post).not.toHaveBeenCalled()
  })

  it('链上配置只在填了值时提交，避免空串覆盖后端', async () => {
    api.post.mockResolvedValue(ok({ id: 1 }))
    await saveCollectible({ name: 'a', chainType: 'axiu', contract: '' })
    expect(api.post.mock.calls[0][1]).toMatchObject({ chain_type: 'axiu' })
    expect('contract' in api.post.mock.calls[0][1]).toBe(false)
  })
})

describe('toggleCollectibleStatus / releaseCollectible · 发售动作', () => {
  it('上架走 release 接口且返回规整后的前端状态', async () => {
    api.post.mockResolvedValue(ok(null))
    const res = await toggleCollectibleStatus(9696, 'online')
    expect(api.post).toHaveBeenCalledWith('/collectibles/9696/release', { status: 'onsale' })
    expect(res.data).toBe('onsale')
  })

  it('下架 / 强制售罄走 manage，off 回写成 offline', async () => {
    api.post.mockResolvedValue(ok(null))
    expect((await toggleCollectibleStatus(9696, 'down')).data).toBe('offline')
    expect(api.post.mock.calls[0][1]).toEqual({ action: 'off' })

    expect((await toggleCollectibleStatus(9696, 'forceSoldout')).data).toBe('soldout')
    expect(api.post.mock.calls[1][1]).toEqual({ action: 'soldout' })
  })

  it('动作失败时不透出规整状态，视图按 code 提示', async () => {
    api.post.mockResolvedValue({ code: 4220, message: '库存池不足，当前库存池为 0', data: null })
    const res = await toggleCollectibleStatus(9696, 'online')
    expect(res.code).toBe(4220)
    expect(res.data).toBeNull()
  })

  it('发售配置从不提交 onsale_at，分批量为空才交给后端默认', async () => {
    const bodyOf = (payload) => {
      api.post.mockResolvedValue(ok(null))
      releaseCollectible(payload)
      return api.post.mock.calls[api.post.mock.calls.length - 1][1]
    }

    expect(bodyOf({ id: 9696, releaseQuantity: 30, price: 99, perUserLimit: 2 }))
      .toEqual({ status: 'onsale', release_quantity: 30, price: 99, per_user_limit: 2 })
    expect(bodyOf({ id: 9696, releaseQuantity: '' })).toEqual({ status: 'onsale' })
    // 0 是「全部上架」的显式信号，不能与留空混同
    expect(bodyOf({ id: 9696, releaseQuantity: 0 })).toEqual({ status: 'onsale', release_quantity: 0 })
  })
})

describe('市场归属与推荐（2026-10-09 市场分栏）', () => {
  it('marketType / isMarketRecommended 落到前端字段，后端缺列时兜底活动市场', async () => {
    api.get.mockResolvedValue(ok({
      list: [backendRow({ marketType: 'free', isMarketRecommended: 1 }), backendRow()],
      total: 2
    }))
    const { data } = await getCollectibleList({ page: 1 })
    expect(data.list[0]).toMatchObject({ marketType: 'free', isMarketRecommended: true })
    // 迁移未执行 / 老数据没有这两列时，不能显示成「undefined 市场」
    expect(data.list[1]).toMatchObject({ marketType: 'activity', isMarketRecommended: false })
  })

  it('归属市场筛选原样透传（后端按 market_type 白名单过滤）', async () => {
    api.get.mockResolvedValue(ok({ list: [], total: 0 }))
    await getCollectibleList({ page: 1, marketType: 'free' })
    expect(api.get).toHaveBeenCalledWith('/collectibles', { page: 1, marketType: 'free' })
  })

  it('移动市场打到 market-move，参数用后端的 snake_case', async () => {
    api.post.mockResolvedValue(ok(null))
    await moveCollectibleMarket(9696, 'free')
    expect(api.post).toHaveBeenCalledWith('/collectibles/9696/market-move', { market_type: 'free' })
  })

  it('上/下推荐布尔转 0/1', async () => {
    api.post.mockResolvedValue(ok(null))
    await toggleCollectibleMarketRecommend(9696, true)
    expect(api.post).toHaveBeenCalledWith('/collectibles/9696/market-recommend', { recommended: 1 })
    await toggleCollectibleMarketRecommend(9696, false)
    expect(api.post).toHaveBeenLastCalledWith('/collectibles/9696/market-recommend', { recommended: 0 })
  })

  it('首页轮播「播」开关落到前端字段，后端缺列时兜底成关', async () => {
    api.get.mockResolvedValue(ok({
      list: [backendRow({ isHomeCarouselRecommended: 1 }), backendRow()],
      total: 2
    }))
    const { data } = await getCollectibleList({ page: 1 })
    expect(data.list[0]).toMatchObject({ isHomeCarouselRecommended: true })
    // 迁移未执行时开关要显示为关，不能是 undefined
    expect(data.list[1]).toMatchObject({ isHomeCarouselRecommended: false })
  })

  it('上/下首页轮播布尔转 0/1，打到 home-carousel', async () => {
    api.post.mockResolvedValue(ok(null))
    await toggleCollectibleHomeCarousel(9696, true)
    expect(api.post).toHaveBeenCalledWith('/collectibles/9696/home-carousel', { on: 1 })
    await toggleCollectibleHomeCarousel(9696, false)
    expect(api.post).toHaveBeenLastCalledWith('/collectibles/9696/home-carousel', { on: 0 })
  })
})
