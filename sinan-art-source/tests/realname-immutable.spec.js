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
// vant 已被上面的工厂 mock 掉，这里拿到的就是那个 vi.fn()
import { showToast } from 'vant'

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

/**
 * 身份证本地校验必须与后端同口径（sinan-nft-backend/app/controller/User.php）
 *
 * 后端不只校验「18 位」，还要求地区码首位非 0、出生年 19xx/20xx、月 01-12、日 01-31。
 * 前端原先用 /^\d{17}[\dXx]$/，乱填 18 位数字能过本地校验、提交才被后端弹回，报错晚一步。
 * 后台「实名审核模式=auto」是现状默认：格式校验成了唯一防线，两端更不能再各判一次。
 */
describe('身份证格式：前端与后端同口径', () => {
  const NAME = '张三'

  it.each([
    ['地区码首位为 0', '010101199003077578'],
    ['月份 13', '110101199013077578'],
    ['日期 34', '123456789012345678'],
    ['全 1 乱填', '111111111111111111'],
    ['末尾位不是数字或 X', '11010119900307757A'],
  ])('%s 应在本地就被拦住（不必等后端弹回）', async (_label, badId) => {
    const wrapper = await mountPage(0)
    const inputs = wrapper.findAll('input')
    await inputs[0].setValue(NAME)
    await inputs[1].setValue(badId)

    expect(wrapper.get('button').classes()).toContain('is-disabled')
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('请输入 18 位有效身份证号')
    expect(shared.post).not.toHaveBeenCalled()
  })

  it('合法身份证号（18 位、含小写 x）解除禁用并成功提交', async () => {
    const wrapper = await mountPage(0)
    const inputs = wrapper.findAll('input')
    await inputs[0].setValue(NAME)
    await inputs[1].setValue('11010119900307757x')

    expect(wrapper.get('button').classes()).not.toContain('is-disabled')
    await wrapper.get('button').trigger('click')
    expect(shared.post).toHaveBeenCalledWith('/user/realname', { realName: NAME, idCard: '11010119900307757X' })
  })
})

/**
 * auto 模式下后端直接返回 status=approved，页面提示不能再是「等待审核」
 * （manual 模式返回 pending，两条文案都得对着各自的返回值走）
 */
describe('提交结果文案跟随审核模式', () => {
  const fillValid = async () => {
    const wrapper = await mountPage(0)
    const inputs = wrapper.findAll('input')
    await inputs[0].setValue('张三')
    await inputs[1].setValue('110101199003077578')
    return wrapper
  }

  it('返回 approved 时提示已通过', async () => {
    shared.post.mockResolvedValue({ status: 'approved' })
    const wrapper = await fillValid()
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(showToast).toHaveBeenCalledWith('实名认证已通过')
  })

  it('返回 pending 时提示等待审核', async () => {
    shared.post.mockResolvedValue({ status: 'pending' })
    const wrapper = await fillValid()
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(showToast).toHaveBeenCalledWith('已提交，等待审核')
  })
})
