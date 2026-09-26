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

import { getRefundList, refundAction } from '@/api'
import { REFUND_STATUS } from '@/utils/maps'

/**
 * 退款状态机映射（src/api/index.js REFUND_STATUS + src/utils/maps.js）
 *
 * 后端 refunds.status 约定 1待审批 / 2已批准 / 3已退款 / 4已拒绝（见 full_init.sql 列注释
 * 与 qa/sit_t7_refund.php 7.3.12、7.5.4 断言）。两步审批里 2 只是「批准但钱未入账」，
 * 曾把 2 和 3 都映射成 approved（标签「已退款」），管理员就无法分辨待执行与已退款，
 * 也就永远不会去点「执行退款」—— 资金卡在已批准状态。
 */
const ok = (data) => ({ code: 0, message: 'ok', data })

const refundRow = (status) => ({
  id: 11,
  orderNo: 'SO202609270001',
  userId: 7,
  username: '张三',
  collectibleName: '大绵羊',
  amount: '199.90',
  reason: '不想要了',
  status,
  createdAt: '2026-09-27 10:00:00',
  updatedAt: '2026-09-27 11:00:00'
})

beforeEach(() => {
  Object.values(api).forEach((fn) => fn?.mockReset?.())
  Object.values(api.http).forEach((fn) => fn.mockReset())
})

describe('getRefundList · 状态四值映射', () => {
  it('1/2/3/4 分别映射为 pending/approved/refunded/rejected', async () => {
    api.get.mockResolvedValue(ok({
      list: [refundRow(1), refundRow(2), refundRow(3), refundRow(4)],
      total: 4
    }))
    const { data } = await getRefundList({ page: 1 })
    expect(data.list.map((r) => r.status)).toEqual(['pending', 'approved', 'refunded', 'rejected'])
  })

  it('已批准与已退款必须是不同标签，且金额/时间照常转换', async () => {
    api.get.mockResolvedValue(ok({ list: [refundRow(2)], total: 1 }))
    const { data } = await getRefundList({ page: 1 })
    const row = data.list[0]
    expect(REFUND_STATUS[row.status].label).toBe('已批准')
    expect(REFUND_STATUS.approved.label).not.toBe(REFUND_STATUS.refunded.label)
    expect(row).toMatchObject({ id: 11, amount: 199.9, userName: '张三', applyTime: '2026-09-27 10:00:00' })
  })

  it('未知状态回落 pending，避免表格渲染成空白', async () => {
    api.get.mockResolvedValue(ok({ list: [refundRow(99)], total: 1 }))
    const { data } = await getRefundList({ page: 1 })
    expect(data.list[0].status).toBe('pending')
  })
})

describe('refundAction · 三步审批都走对端点', () => {
  it('approve / reject 走 approve 端点并带意见', async () => {
    api.post.mockResolvedValue(ok(null))
    await refundAction(11, 'approve', { reason: '同意' })
    await refundAction(11, 'reject', { reason: '不符' })
    expect(api.post.mock.calls[0]).toEqual(['/refunds/11/approve', { action: 'approve', comment: '同意' }])
    expect(api.post.mock.calls[1]).toEqual(['/refunds/11/approve', { action: 'reject', comment: '不符' }])
  })

  it('execute 走 execute 端点（批准后的入账动作，列表页必须能点）', async () => {
    api.post.mockResolvedValue(ok(null))
    await refundAction(11, 'execute')
    expect(api.post).toHaveBeenCalledWith('/refunds/11/execute', { refund_channel: 'original' })
  })

  it('未知动作不发请求', async () => {
    const res = await refundAction(11, 'cancel')
    expect(res.code).toBe(4220)
    expect(api.post).not.toHaveBeenCalled()
  })
})
