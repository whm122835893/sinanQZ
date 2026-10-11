import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const shared = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  showToast: vi.fn(),
  markRead: vi.fn()
}))

vi.mock('@/utils/request', () => ({ default: { get: shared.get, post: shared.post } }))
vi.mock('vant', () => ({ showToast: shared.showToast, showConfirmDialog: vi.fn() }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), back: vi.fn() }),
  useRoute: () => ({ fullPath: '/user/orders' })
}))
// loginGate 顶层 import 了 @/router，真实引入会把整棵路由树拖进测试；本页只需要 requireLogin 放行
vi.mock('@/utils/loginGate', () => ({ useLoginGate: () => ({ requireLogin: () => true, visible: { value: false } }) }))
vi.mock('@/stores/inbox', () => ({ useInboxStore: () => ({ markRead: shared.markRead }) }))

import request from '@/utils/request'
import Orders from '@/views/user/Orders.vue'
import { useOrderStore } from '@/stores/order'
import { useUserStore } from '@/stores/user'

/**
 * 藏品到账的两条前端口径（2026-10-11）
 *
 * ① 空投卡片头部显示**藏品编号**（后端 serial，形如 SN-9-0001），
 *    不再显示 nft_airdrop_records 的行号「空投单号 #10」——那是后台台账编号，用户拿着它
 *    在「我的藏品」里什么都对不上。发放失败/待发放没有资产行，才退回显示记录 ID。
 * ② 开盒/抽奖中奖后端也写收件箱记录，但同页已有结果弹窗，所以接口回传 inboxId、
 *    前端当场标记已读 —— 记录留档，15s 轮询不会再弹第二次。
 */
describe('order store · 空投记录下发藏品编号', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    shared.get = vi.fn()
    shared.post = vi.fn()
  })

  const airdropPayload = (over = {}) => ({
    id: 10, collectibleId: 9, name: '莲塘乳鹿图', image: '/lotus.png',
    quantity: 1, status: 'issued', issuedAt: '2026-10-11 09:00:00',
    createdAt: '2026-10-11 09:00:00', serial: 'SN-9-0001', userCollectibleId: 1, ...over
  })

  async function loadAirdrops(list) {
    // 直接改 request.get：vi.mock 工厂在模块初始化时就取走了 shared.get 的引用，
    // 只替换 shared.get 不会作用到被测代码
    request.get = vi.fn().mockImplementation((url) =>
      Promise.resolve(url.includes('airdrops') ? { list } : []))
    await useOrderStore().fetchAirdrops()
  }

  it('serial 落到卡片的 no 字段，头部才有编号可显示', async () => {
    await loadAirdrops([airdropPayload()])
    const [row] = useOrderStore().airdrops
    expect(row.no).toBe('SN-9-0001')
    expect(row.id).toBe('10')
  })

  it('发放失败没有资产行：serial 为 null 时退成空串，交给视图退回显示记录 ID', async () => {
    await loadAirdrops([airdropPayload({ status: 'failed', issuedAt: null, serial: null, userCollectibleId: null })])
    expect(useOrderStore().airdrops[0].no).toBe('')
  })
})

describe('Orders.vue · 空投卡片头部文案', () => {
  const headers = async (wrapper) => {
    await wrapper.find('.mine-orders__tab:nth-child(5)').trigger('click')
    await flushPromises()
    return wrapper.findAll('.order-card__head').map((h) => h.text())
  }

  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    shared.markRead.mockReset()
  })

  async function mountWithAirdrops(list) {
    request.get = vi.fn().mockImplementation((url) =>
      Promise.resolve(url.includes('airdrops') ? { list } : []))
    const user = useUserStore()
    user.setToken('test-token')
    const wrapper = mount(Orders, { global: { stubs: { AppNavBar: true, AppEmpty: true } } })
    await flushPromises()
    return wrapper
  }

  const airdropPayload = (over = {}) => ({
    id: 10, collectibleId: 9, name: '莲塘乳鹿图', image: '/lotus.png',
    quantity: 1, status: 'issued', issuedAt: '2026-10-11 09:00:00',
    createdAt: '2026-10-11 09:00:00', serial: 'SN-9-0001', userCollectibleId: 1, ...over
  })

  // 编号展示口径（2026-10-11 定）：C 端一律走 utils/serial 的 formatNo，
  // SN-9-0001 这种机器编号只显示尾部序号 → 「藏品编号 #1」
  it('已发放的空投显示藏品编号，不再显示「空投单号 #10」', async () => {
    const wrapper = await mountWithAirdrops([airdropPayload()])
    const [head] = await headers(wrapper)
    expect(head).toContain('藏品编号 #1')
    expect(head).not.toContain('SN-9-0001')
    expect(head).not.toContain('空投单号')
  })

  it('发放失败没有编号，退回显示记录 ID 而不是空着头部', async () => {
    const wrapper = await mountWithAirdrops([
      airdropPayload({ id: 11, status: 'failed', issuedAt: null, serial: null, userCollectibleId: null })
    ])
    const [head] = await headers(wrapper)
    expect(head).toContain('空投单号 #11')
    expect(head).toContain('发放失败')
  })
})

describe('user store · 开盒结果不重复弹窗', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    localStorage.clear()
    shared.markRead.mockReset()
  })

  it('开盒接口回传 inboxId 时立即标记已读（留档但不让轮询再弹一次）', async () => {
    const user = useUserStore()
    user.setToken('test-token')
    request.post = vi.fn().mockResolvedValue({
      prize: { collectibleId: 9, name: '莲塘乳鹿图', no: 'SN-9-0001' },
      inboxId: 77
    })
    request.get = vi.fn().mockResolvedValue([])

    await user.openBlindbox(3, 'Tx@123456')

    expect(shared.markRead).toHaveBeenCalledWith(77)
  })

  it('老后端没回传 inboxId 也不能报错（开盒本身要算成功）', async () => {
    const user = useUserStore()
    user.setToken('test-token')
    request.post = vi.fn().mockResolvedValue({ prize: { collectibleId: 9, name: 'x', no: 'SN-9-0002' } })
    request.get = vi.fn().mockResolvedValue([])

    await expect(user.openBlindbox(3, 'Tx@123456')).resolves.toBeTruthy()
    expect(shared.markRead).not.toHaveBeenCalled()
  })
})
