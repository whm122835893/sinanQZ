import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const shared = vi.hoisted(() => ({ post: vi.fn(), showToast: vi.fn(), back: vi.fn() }))

vi.mock('vant', () => ({ showToast: shared.showToast }))
vi.mock('vue-router', () => ({ useRouter: () => ({ back: shared.back, push: vi.fn() }) }))
vi.mock('@/utils/request', () => ({ default: { post: shared.post } }))
vi.mock('@/utils/useCaptcha', () => ({
  useCaptcha: () => ({ require: vi.fn(), invalidate: vi.fn() })
}))

import ChangePwd from '@/views/auth/ChangePwd.vue'
import OpPwd from '@/views/auth/OpPwd.vue'
import { useUserStore } from '@/stores/user'

/**
 * 改密/改操作密码后的会话续用
 *
 * 这两个接口都会写 logout_before 吊销该账号全部旧令牌（P0 会话安全），
 * 响应里必须带回新令牌并由页面换用：只吊销不换用，用户改完密码就当场掉线，
 * 而 router.back() 之后没有任何提示，表现为「余额不见了」。
 */
async function submit(View, values) {
  const wrapper = mount(View, { global: { stubs: { CaptchaDialog: true } } })
  const inputs = wrapper.findAll('.app-input__native')
  for (let i = 0; i < values.length; i++) await inputs[i].setValue(values[i])
  await wrapper.find('.app-btn').trigger('click')
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  setActivePinia(createPinia())
  Object.values(shared).forEach((fn) => fn.mockReset())
  localStorage.setItem('jc_token', 'OLD-TOKEN')
})

describe('账户安全页改密', () => {
  it('登录密码重置后换用后端下发的新令牌', async () => {
    shared.post.mockResolvedValue({ token: 'NEW-PWD-TOKEN' })
    await submit(ChangePwd, ['123456', 'Abc123456', 'Abc123456'])

    expect(shared.post).toHaveBeenCalledWith('/user/password/reset', {
      code: '123456',
      newPassword: 'Abc123456'
    })
    expect(useUserStore().token).toBe('NEW-PWD-TOKEN')
    expect(localStorage.getItem('jc_token')).toBe('NEW-PWD-TOKEN')
  })

  it('操作密码重置后同样换用新令牌', async () => {
    shared.post.mockResolvedValue({ token: 'NEW-TRADE-TOKEN' })
    await submit(OpPwd, ['13900000001', '123456', '864213', '864213'])

    expect(shared.post).toHaveBeenCalledWith('/user/password/trade/reset', {
      code: '123456',
      newPassword: '864213'
    })
    expect(useUserStore().token).toBe('NEW-TRADE-TOKEN')
    expect(localStorage.getItem('jc_token')).toBe('NEW-TRADE-TOKEN')
  })

  it('提交失败时不动本地令牌', async () => {
    shared.post.mockRejectedValue(new Error('验证码错误或已过期'))
    await submit(ChangePwd, ['000000', 'Abc123456', 'Abc123456'])

    expect(useUserStore().token).toBe('OLD-TOKEN')
    expect(shared.showToast).toHaveBeenCalledWith('验证码错误或已过期')
  })
})
