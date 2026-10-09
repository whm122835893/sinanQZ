import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const shared = vi.hoisted(() => ({ get: vi.fn(), push: vi.fn(), showToast: vi.fn() }))

vi.mock('@/utils/request', () => ({ default: { get: shared.get, post: vi.fn(), put: vi.fn() } }))
vi.mock('@/router', () => ({ default: { push: shared.push } }))
vi.mock('vant', () => ({ showToast: shared.showToast }))

import { ensureRealname, ensureTradePassword } from '@/utils/purchaseGate'
import { useUserStore } from '@/stores/user'

/**
 * 购买链路上的两道前置拦截（时机比内容更重要）
 *
 * 产品口径：实名认证在「点击购买」时拦，未设置交易密码在「进入密码输入」时拦。
 * 改这两处时机前必须先看完这条约定——早拦会把只想看价格的人挡住，
 * 晚拦（原实现）则让用户白输一遍 6 位支付密码才被后端打回。
 *
 * 另外两个容易被忽略的点也在这里锁住：
 *   1) 本地缓存是过时的（后台刚过审 / 刚设完操作密码），拦之前要先跟服务端确认；
 *   2) 判定要 await 一次接口，连点不能触发两次跳转。
 */
function profile(over = {}) {
  return {
    nickname: '司南-1293', phone: '175****1293', isRealName: false, hasPassword: true,
    realnameStatus: 0, hasTransactionPassword: false, inviteCode: 'AMBNSMJ3',
    wallet: { balance: 0, available: 0, frozen: 0, points: 0 }, ...over
  }
}

beforeEach(() => {
  // store 初值从 localStorage 读，不清掉会让上一条用例的缓存状态漏进下一条
  localStorage.removeItem('jc_user_info')
  // 没有 token 时 fetchUserInfo 直接返回缓存、根本不打 /user/profile，
  // 「先跟服务端确认再拦」这条链路就测不到了，所以必须给一个登录态
  localStorage.setItem('jc_token', 'TEST-TOKEN')
  setActivePinia(createPinia())
  shared.get.mockReset()
  shared.push.mockReset()
  shared.showToast.mockReset()
})

describe('实名认证：点购买时拦', () => {
  it('缓存里已实名：直接放行，不再打接口', async () => {
    const user = useUserStore()
    user.setUserInfo({ isRealName: true })

    await expect(ensureRealname()).resolves.toBe(true)
    expect(shared.get).not.toHaveBeenCalled()
    expect(shared.push).not.toHaveBeenCalled()
  })

  it('缓存过时但服务端已过审：放行且不跳转', async () => {
    shared.get.mockResolvedValue(profile({ isRealName: true, realnameStatus: 2 }))

    await expect(ensureRealname()).resolves.toBe(true)
    expect(shared.get).toHaveBeenCalledWith('/user/profile')
    expect(shared.push).not.toHaveBeenCalled()
  })

  it('未实名：拦下并带去实名认证页', async () => {
    shared.get.mockResolvedValue(profile())

    await expect(ensureRealname()).resolves.toBe(false)
    expect(shared.showToast).toHaveBeenCalledWith('请先完成实名认证')
    expect(shared.push).toHaveBeenCalledWith('/user/realname')
  })

  it('审核中与已驳回给不同文案，不让用户反复提交', async () => {
    shared.get.mockResolvedValue(profile({ realnameStatus: 1 }))
    await expect(ensureRealname()).resolves.toBe(false)
    expect(shared.showToast).toHaveBeenCalledWith('实名认证审核中，通过后方可购买')

    shared.get.mockResolvedValue(profile({ realnameStatus: 3 }))
    await expect(ensureRealname()).resolves.toBe(false)
    expect(shared.showToast).toHaveBeenCalledWith('实名认证未通过，请重新提交认证')
  })
})

describe('交易密码：进入密码输入时拦', () => {
  it('已设置操作密码：直接放行，不弹空键盘前不打接口', async () => {
    const user = useUserStore()
    user.setUserInfo({ hasTransactionPassword: true })

    await expect(ensureTradePassword()).resolves.toBe(true)
    expect(shared.get).not.toHaveBeenCalled()
    expect(shared.push).not.toHaveBeenCalled()
  })

  it('未设置：提示后跳操作密码设置页', async () => {
    shared.get.mockResolvedValue(profile())

    await expect(ensureTradePassword()).resolves.toBe(false)
    expect(shared.showToast).toHaveBeenCalledWith('请先设置操作密码')
    expect(shared.push).toHaveBeenCalledWith('/auth/op-pwd')
  })

  it('刚设置完（服务端已为 true）：不再误拦', async () => {
    shared.get.mockResolvedValue(profile({ hasTransactionPassword: true }))

    await expect(ensureTradePassword()).resolves.toBe(true)
    expect(shared.push).not.toHaveBeenCalled()
  })
})

describe('判定期间连点', () => {
  it('并发两次只发起一次校验、一次跳转', async () => {
    let resolveProfile
    shared.get.mockImplementation(() => new Promise((r) => { resolveProfile = r }))

    const first = ensureRealname()
    const second = ensureRealname()
    resolveProfile(profile())
    await expect(first).resolves.toBe(false)
    await expect(second).resolves.toBe(false)

    expect(shared.get).toHaveBeenCalledTimes(1)
    expect(shared.push).toHaveBeenCalledTimes(1)
  })
})
