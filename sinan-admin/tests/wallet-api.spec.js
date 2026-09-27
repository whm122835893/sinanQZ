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

import { getWalletTransactions } from '@/api'

/**
 * 钱包流水适配层（src/api/index.js）
 *
 * 后端 WalletController::transactions 只读 `transType` 参数，视图筛选用的是 `type`；
 * 名字对不上时查询条件被静默丢弃，页面显示"全部流水"却让用户以为筛选生效。
 * 退款入账从 reward 独立成 refund 类型后，这一层是它唯一的管理端入口，必须锁住。
 */
const ok = (data) => ({ code: 0, message: 'ok', data })

beforeEach(() => {
  api.get.mockReset()
  api.get.mockResolvedValue(ok({ list: [], total: 0 }))
})

describe('getWalletTransactions 类型筛选参数', () => {
  it('把 refund 以 transType 传给后端并删除 type', async () => {
    await getWalletTransactions({ page: 1, pageSize: 20, type: 'refund' })

    const query = api.get.mock.calls[0][1]
    expect(api.get.mock.calls[0][0]).toBe('/wallet/transactions')
    expect(query.transType).toBe('refund')
    expect(query).not.toHaveProperty('type')
  })

  it('消费语义 consume 映射为后端枚举 buy', async () => {
    await getWalletTransactions({ type: 'consume' })

    const query = api.get.mock.calls[0][1]
    expect(query.transType).toBe('buy')
    expect(query).not.toHaveProperty('type')
  })

  it('未选类型时不下发空的 transType', async () => {
    await getWalletTransactions({ page: 1, pageSize: 20 })

    expect(api.get.mock.calls[0][1]).not.toHaveProperty('transType')
  })
})

describe('getWalletTransactions 行映射', () => {
  it('transType refund 映射为前端 type refund 并保留金额方向', async () => {
    api.get.mockResolvedValue(ok({
      total: 1,
      list: [{
        id: 2090,
        userId: 841,
        transType: 'refund',
        title: '订单退款入账',
        direction: 1,
        amount: '1.00',
        balanceAfter: '100.00',
        bizNo: 'RF260928011424641',
        createdAt: '2026-09-28 01:15:18.000',
        username: '司南-0928',
        userPhone: '156****0928'
      }]
    }))

    const res = await getWalletTransactions({ type: 'refund' })

    expect(res.data.total).toBe(1)
    expect(res.data.list[0]).toMatchObject({
      id: 2090,
      type: 'refund',
      direction: 1,
      amount: 1,
      balanceAfter: 100,
      userName: '司南-0928'
    })
  })

  it('buy 在视图侧统一为 consume', async () => {
    api.get.mockResolvedValue(ok({
      total: 1,
      list: [{ id: 2089, userId: 841, transType: 'buy', title: '购买藏品', direction: 2, amount: '1.00', balanceAfter: '99.00' }]
    }))

    const res = await getWalletTransactions({})
    expect(res.data.list[0].type).toBe('consume')
  })
})
