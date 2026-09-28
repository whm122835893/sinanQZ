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
import { isIncome, fmtMoney } from '@/utils/format'

/**
 * 钱包流水「方向 / 发生额」判定（src/utils/format.js isIncome）
 *
 * 后端 nft_wallet_transactions.direction 是 1=收入 / 2=支出 的正数枚举
 * （见 app/controller/Wallet.php 与 admin/controller/WalletController.php 的统计口径），
 * 不是正负号。钱包流水页曾按 direction > 0 判定，于是所有消费、提现、退款扣回
 * 行都渲染成「收入 +金额」，账面看起来只进不出——表格、CSV 导出、颜色三处同源。
 */
const ok = (data) => ({ code: 0, message: 'ok', data })

const txRow = (direction, amount) => ({
  id: 1,
  userId: 7,
  username: '司南-0955',
  userPhone: '156****0955',
  transType: 'buy',
  title: '购买藏品',
  direction,
  amount,
  balanceAfter: 99.99,
  createdAt: '2026-09-28 03:38:06.000'
})

beforeEach(() => {
  api.get.mockReset()
  api.get.mockResolvedValue(ok({ list: [], total: 0 }))
})

describe('isIncome 方向枚举', () => {
  it('1 是收入、2 是支出（不得按正负号判定）', () => {
    expect(isIncome(1)).toBe(true)
    expect(isIncome(2)).toBe(false)
  })

  it('后端字符串形式的枚举值同样判定（适配层 n() 之外也要稳）', () => {
    expect(isIncome('1')).toBe(true)
    expect(isIncome('2')).toBe(false)
  })

  it('缺失值按支出渲染，不把未知方向标成收入', () => {
    expect(isIncome(null)).toBe(false)
    expect(isIncome(undefined)).toBe(false)
  })

  it('适配层把 direction 透传为数字，购买行据此渲染为「-」', async () => {
    api.get.mockResolvedValue(ok({ list: [txRow(2, 0.01)], total: 1 }))
    const res = await getWalletTransactions({ page: 1, pageSize: 20 })
    const [row] = res.data.list

    expect(row.direction).toBe(2)
    expect(isIncome(row.direction)).toBe(false)
    expect(`${isIncome(row.direction) ? '+' : '-'}${fmtMoney(row.amount)}`).toBe('-0.01')
  })

  it('退款入账行为收入且加号（refund 与 reward 同方向）', async () => {
    api.get.mockResolvedValue(ok({ list: [txRow(1, 0.01)], total: 1 }))
    const res = await getWalletTransactions({ page: 1, pageSize: 20 })
    const [row] = res.data.list

    expect(isIncome(row.direction)).toBe(true)
    expect(`${isIncome(row.direction) ? '+' : '-'}${fmtMoney(row.amount)}`).toBe('+0.01')
  })
})
