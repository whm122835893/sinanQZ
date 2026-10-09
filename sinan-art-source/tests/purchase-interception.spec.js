import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const shared = vi.hoisted(() => ({
  push: vi.fn(),
  back: vi.fn(),
  showToast: vi.fn(),
  get: vi.fn(),
  post: vi.fn(),
  fetchDetail: vi.fn(),
  fetchResale: vi.fn(),
  createOrder: vi.fn(),
  payOrder: vi.fn()
}))

// 受测页面里唯一需要的路由能力就是 push/back，整模块替换掉，免得真路由表被拉进来
let route = { params: { mode: 'release', id: '1' }, query: {}, fullPath: '/pay/release/1' }
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: shared.push, back: shared.back, replace: shared.push }),
  useRoute: () => route
}))
vi.mock('vant', () => ({ showToast: shared.showToast }))
// purchaseGate 用的是 router 单例（不是 useRouter），同样指到同一个 spy 上
vi.mock('@/router', () => ({ default: { push: (...a) => shared.push(...a) } }))
vi.mock('@/utils/request', () => ({ default: { get: shared.get, post: shared.post, put: shared.post } }))
vi.mock('@/utils/loginGate', () => ({ useLoginGate: () => ({ requireLogin: () => true }) }))
vi.mock('@/stores/collection', () => ({
  useCollectionStore: () => ({
    fetchDetail: shared.fetchDetail,
    fetchResale: shared.fetchResale,
    // 详情页的发售状态来自首页列表缓存；单测里固定成「发售中」，按钮才可点
    getFeaturedById: () => null,
    getSaleStatus: () => 'selling'
  })
}))
vi.mock('@/stores/order', () => ({
  useOrderStore: () => ({ createOrder: shared.createOrder, payOrder: shared.payOrder })
}))
vi.mock('@/stores/site', () => ({ useSiteStore: () => ({ resaleFeeRate: 5 }) }))

// 用户态用一个可控的最小替身：两个闸门读的就是这两个布尔 + refreshQuietly
const fakeUser = {
  isLoggedIn: true,
  userInfo: { isRealName: false, hasTransactionPassword: false, realnameStatus: 0, phone: '175****1293' },
  ownedCount: () => 0,
  inventory: [],
  fetchInventory: vi.fn(() => Promise.resolve()),
  refreshQuietly: vi.fn(() => Promise.resolve()),
  setUserInfo: vi.fn(),
  findUserCollectibleId: () => 7,
  // 详情页寄售区依赖这几项：给中性值，让按钮处于「可寄售」而不是报错
  consignCooldownRemain: () => 0,
  isNoLocked: () => false,
  consign: vi.fn(() => Promise.resolve({ actualAmount: 0 })),
  openBlindbox: vi.fn(() => Promise.resolve({ prize: {} })),
  transfer: vi.fn(() => Promise.resolve())
}
vi.mock('@/stores/user', () => ({ useUserStore: () => fakeUser }))

import Pay from '@/views/Pay.vue'
import CollectionDetail from '@/views/CollectionDetail.vue'

/**
 * 购买链路上的拦截时机（不是拦截内容）
 *
 * 实名认证必须在「点击购买」时就把人拦下；「未设置操作密码」只能在
 * 「要输密码的那一步」拦。两者的报错文案早就有，出问题的一直是时机：
 * 原来两者都堆在填完 6 位支付密码提交之后，用户白输一遍密码，
 * 还以为「能弹键盘 = 我资格没问题」。
 *
 * 因此这里锁的是：谁在什么时候被挡、挡住了有没有把用户送去该去的页面、
 * 以及最关键的——键盘有没有在不合格时被弹出来。
 */
// 详情页的寄售/开盒弹窗用了全局注册的 van-popup：不 stub 的话，
// 未识别组件会把插槽内容当普通内容渲染，detail 还是 null 就崩在 coverImage 上
const stubs = {
  AppNavBar: true, AppIcon: true, AppModal: true, AppButton: true,
  AppInput: true, AppListItem: true, 'van-popup': true, 'van-overlay': true
}

function resetUser(over = {}) {
  Object.assign(fakeUser.userInfo, { isRealName: false, hasTransactionPassword: false, realnameStatus: 0 }, over)
  fakeUser.refreshQuietly.mockReset().mockResolvedValue()
}

async function mountPay() {
  const wrapper = mount(Pay, { global: { stubs } })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.values(shared).forEach((fn) => typeof fn?.mockReset === 'function' && fn.mockReset())
  route = { params: { mode: 'release', id: '1' }, query: {}, fullPath: '/pay/release/1' }
  shared.get.mockResolvedValue([{ method: 'balance', name: '余额支付' }])
  shared.fetchDetail.mockResolvedValue({
    title: '测试藏品', coverImage: '/uploads/a.png', issueCount: '100', circulationCount: '80', price: '9.90'
  })
})

describe('支付页：实名拦在进入页面时，密码状态拦在输密码时', () => {
  it('未实名：进不了支付页，直接被带去实名认证', async () => {
    resetUser()
    await mountPay()

    expect(shared.push).toHaveBeenCalledWith('/user/realname')
    expect(shared.showToast).toHaveBeenCalledWith('请先完成实名认证')
    // 藏品详情都不该去拉：人不该看到一个与自己无关的支付页
    expect(shared.fetchDetail).not.toHaveBeenCalled()
  })

  it('已实名：正常进页，此时不该因为「没设操作密码」被拦', async () => {
    resetUser({ isRealName: true })
    const wrapper = await mountPay()

    expect(shared.fetchDetail).toHaveBeenCalledWith('1')
    expect(shared.push).not.toHaveBeenCalled()
    expect(wrapper.find('.pay-buy__btn').exists()).toBe(true)
  })

  it('已实名但未设操作密码：点确认支付不弹键盘，改跳设置页', async () => {
    resetUser({ isRealName: true })
    const wrapper = await mountPay()

    await wrapper.find('.pay-buy__btn').trigger('click')
    await flushPromises()

    expect(wrapper.find('.pwd-mask').exists()).toBe(false)
    expect(shared.showToast).toHaveBeenCalledWith('请先设置操作密码')
    expect(shared.push).toHaveBeenCalledWith('/auth/op-pwd')
  })

  it('设置完操作密码回来：同一位置才真正把键盘弹出来', async () => {
    resetUser({ isRealName: true })
    const wrapper = await mountPay()

    fakeUser.userInfo.hasTransactionPassword = true
    await wrapper.find('.pay-buy__btn').trigger('click')
    await flushPromises()

    expect(wrapper.find('.pwd-mask').exists()).toBe(true)
    expect(shared.push).not.toHaveBeenCalled()
  })

  it('缓存说已实名：直接放行不再打接口（后台撤销过审由后端兜底，报错同为「请先完成实名认证」）', async () => {
    resetUser({ isRealName: true })
    await mountPay()

    expect(fakeUser.refreshQuietly).not.toHaveBeenCalled()
    expect(shared.push).not.toHaveBeenCalled()
    expect(shared.fetchDetail).toHaveBeenCalledWith('1')
  })
})

describe('藏品详情页：点击购买即拦实名', () => {
  it('未实名：点「立即购买」不进支付页', async () => {
    resetUser()
    shared.fetchDetail.mockResolvedValue({
      title: '测试藏品', coverImage: '/a.png', price: '9.90', status: 'onsale',
      saleTime: Date.now() - 1000, issueCount: '10', circulationCount: '10', isTransferable: 1
    })
    const wrapper = mount(CollectionDetail, { global: { stubs } })
    await flushPromises()

    const btn = wrapper.find('.detail-buy__btn')
    expect(btn.exists()).toBe(true)
    await btn.trigger('click')
    await flushPromises()

    expect(shared.push).toHaveBeenCalledWith('/user/realname')
    // 关键是没跳到支付路由
    expect(shared.push.mock.calls.some((a) => a[0]?.name === 'pay')).toBe(false)
  })

  it('已实名：点「立即购买」照常进支付页', async () => {
    resetUser({ isRealName: true })
    shared.fetchDetail.mockResolvedValue({
      title: '测试藏品', coverImage: '/a.png', price: '9.90', status: 'onsale',
      saleTime: Date.now() - 1000, issueCount: '10', circulationCount: '10', isTransferable: 1
    })
    const wrapper = mount(CollectionDetail, { global: { stubs } })
    await flushPromises()

    await wrapper.find('.detail-buy__btn').trigger('click')
    await flushPromises()

    expect(shared.push).toHaveBeenCalledWith(expect.objectContaining({ name: 'pay' }))
  })
})
