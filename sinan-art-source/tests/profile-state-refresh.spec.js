import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const shared = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))

vi.mock('vant', () => ({ showToast: vi.fn() }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn(), back: vi.fn(), replace: vi.fn(), go: vi.fn() }),
  useRoute: () => ({ params: {}, query: {} })
}))
vi.mock('@/utils/request', () => ({ default: { get: shared.get, post: shared.post, put: shared.put } }))

import Security from '@/views/user/Security.vue'
import Profile from '@/views/user/Profile.vue'
import { useUserStore } from '@/stores/user'

/**
 * C 端「我的 / 账户安全 / 实名认证」的用户态刷新（走查缺陷 12）
 *
 * isRealName、hasPassword、hasTransactionPassword、realnameStatus 只有
 * fetchUserInfo()（GET /api/user/profile）这一个写入口，而它原先仅在实名认证
 * 提交成功后被调用一次。于是管理端过了实名、用户设了操作密码之后，
 * Profile 与 Security 会长期停留在 localStorage 缓存里的「未认证 / 未设置」——
 * 后端 /auth/login 与 /user/profile 返回的都是对的，纯前端缺一次进页刷新。
 */
const apiProfile = (overrides = {}) => ({
  nickname: '司南-0955',
  username: '司南-0955',
  avatar: '',
  phone: '15600000955',
  isRealName: false,
  hasPassword: true,
  hasTransactionPassword: false,
  realnameStatus: 0,
  realnameRejectReason: '',
  inviteCode: 'MGUF38DZ',
  wallet: { balance: 0, available: 0, frozen: 0, points: 0 },
  ...overrides
})

/** 预置一份「旧缓存」：实名未过、操作密码未设 */
function seedStaleCache() {
  localStorage.setItem('jc_token', 'T')
  localStorage.setItem(
    'jc_user_info',
    JSON.stringify({
      nickname: '司南-0955',
      avatar: '',
      phone: '15600000955',
      isRealName: false,
      hasPassword: true,
      hasTransactionPassword: false,
      realnameStatus: 0,
      inviteCode: 'MGUF38DZ'
    })
  )
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.values(shared).forEach((fn) => fn.mockReset())
  localStorage.clear()
  seedStaleCache()
})

describe('账户安全页进页刷新用户态', () => {
  it('挂载时拉取 /user/profile，把过审与已设密码渲染出来', async () => {
    shared.get.mockResolvedValue(apiProfile({ isRealName: true, hasTransactionPassword: true, realnameStatus: 2 }))
    const wrapper = mount(Security)
    await flushPromises()

    expect(shared.get).toHaveBeenCalledWith('/user/profile')
    expect(wrapper.text()).toContain('已认证')
    expect(wrapper.text()).not.toContain('未认证')
    // 「操作密码」一行：已设置而不是沿用缓存的未设置
    const opRow = wrapper.findAll('.app-list-item').find((n) => n.text().includes('操作密码'))
    expect(opRow.text()).toContain('已设置')
  })

  it('接口失败时保留缓存值渲染，不抛错也不清空', async () => {
    shared.get.mockRejectedValue(new Error('登录已过期，请重新登录'))
    const wrapper = mount(Security)
    await flushPromises()

    expect(wrapper.text()).toContain('未认证')
    expect(useUserStore().userInfo.nickname).toBe('司南-0955')
  })
})

describe('个人信息页进页刷新用户态', () => {
  it('后台过审后进入页面即显示已认证', async () => {
    shared.get.mockResolvedValue(apiProfile({ isRealName: true, realnameStatus: 2 }))
    const wrapper = mount(Profile)
    await flushPromises()

    const realnameRow = wrapper.findAll('.app-list-item').find((n) => n.text().includes('实名认证'))
    expect(realnameRow.text()).toContain('已认证')
  })
})
