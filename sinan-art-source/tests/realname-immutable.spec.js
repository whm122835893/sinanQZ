import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const shared = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
const push = vi.hoisted(() => vi.fn())

vi.mock('vant', () => ({ showToast: vi.fn() }))
vi.mock('vue-router', () => ({
  useRouter: () => ({ push, back: vi.fn(), replace: vi.fn(), go: vi.fn() }),
  useRoute: () => ({ params: {}, query: {} })
}))
vi.mock('@/utils/request', () => ({ default: { get: shared.get, post: shared.post, put: shared.put } }))

import Realname from '@/views/user/Realname.vue'

const apiProfile = (overrides = {}) => ({
  nickname: '司南-0955',
  phone: '15600000955',
  avatar: '',
  isRealName: false,
  hasPassword: true,
  hasTransactionPassword: false,
  realnameStatus: 0,
  realnameRejectReason: '',
  inviteCode: 'MGUF38DZ',
  wallet: { balance: 0, available: 0, frozen: 0, points: 0 },
  ...overrides
})

/** realnameStatus 只来自缓存/接口：预置登录态与初始缓存 */
function seedCache(status) {
  localStorage.setItem('jc_token', 'T')
  localStorage.setItem(
    'jc_user_info',
    JSON.stringify({ nickname: '司南-0955', phone: '15600000955', isRealName: false, realnameStatus: status })
  )
}

const mountPage = async (status, rejectReason = '') => {
  shared.get.mockResolvedValue(
    apiProfile({ isRealName: status === 2, realnameStatus: status, realnameRejectReason: rejectReason })
  )
  const wrapper = mount(Realname)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.values(shared).forEach((fn) => fn.mockReset())
  push.mockReset()
  localStorage.clear()
  seedCache(0)
})

/**
 * 实名信息一经审核通过即与账号身份绑定，不可修改（产品规则）
 *
 * 后端 POST /api/user/realname 对 realname_status=2 直接拒绝，原先的「修改认证信息」
 * 按钮点下去只会拿到一句失败提示，属于给了个永远走不通的入口。
 * 已认证视图改为只读 + 联系客服；待审核（status=1）后端同样拒绝重复提交，也只读。
 */
describe('已认证：只读展示，不提供修改入口', () => {
  it('status=2 渲染联系客服而不是修改认证信息，且表单不可达', async () => {
    const wrapper = await mountPage(2)

    expect(wrapper.text()).toContain('已通过实名认证')
    expect(wrapper.text()).toContain('实名认证已通过，实名信息不可修改')
    expect(wrapper.text()).not.toContain('修改认证信息')
    expect(wrapper.text()).not.toContain('提交认证')
    expect(wrapper.find('input').exists()).toBe(false)
    const buttons = wrapper.findAll('button').map((b) => b.text())
    expect(buttons).toEqual(['联系客服'])
  })

  it('点击联系客服跳转客服页', async () => {
    const wrapper = await mountPage(2)
    await wrapper.get('button').trigger('click')
    expect(push).toHaveBeenCalledWith('/user/service')
  })

  it('缓存里是未提交、接口返回已认证时，进页刷新后同样不出现修改入口', async () => {
    seedCache(0)
    const wrapper = await mountPage(2)

    expect(shared.get).toHaveBeenCalledWith('/user/profile')
    expect(wrapper.text()).not.toContain('修改认证信息')
    expect(wrapper.text()).toContain('实名认证已通过，实名信息不可修改')
  })
})

describe('审核中：同样只读（后端拒绝重复提交）', () => {
  it('status=1 展示审核中结果页，不出现提交表单与联系客服', async () => {
    const wrapper = await mountPage(1)

    expect(wrapper.text()).toContain('实名认证审核中')
    expect(wrapper.text()).not.toContain('提交认证')
    expect(wrapper.text()).not.toContain('联系客服')
    expect(wrapper.find('input').exists()).toBe(false)
  })
})

describe('未提交 / 已驳回：仍可填写提交', () => {
  it('status=0 渲染认证表单', async () => {
    const wrapper = await mountPage(0)

    expect(wrapper.findAll('.app-input__label').map((n) => n.text())).toEqual(['真实姓名', '身份证号'])
    expect(wrapper.get('button').text()).toBe('提交认证')
  })

  it('status=3 渲染表单并带出驳回原因', async () => {
    const wrapper = await mountPage(3, '证件照片模糊')

    expect(wrapper.text()).toContain('驳回原因：证件照片模糊')
    expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['提交认证'])
  })
})
