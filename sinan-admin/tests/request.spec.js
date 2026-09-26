import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('element-plus', () => ({ ElMessage: { error: vi.fn() } }))

import { ElMessage } from 'element-plus'
import request, { http } from '@/utils/request'

/**
 * admin 请求层（src/utils/request.js）
 *
 * 后端 admin 应用成功码是 200、C 端是 0，这一层把 200 归一成 0，
 * 视图层的 `res.code === 0` 判定才成立；同时它还负责会话失效清理、
 * 静默请求不弹全局错误、GET 分页参数归一。这几条都直接拦截在改动的拦截器上，
 * 因此这里按拦截器函数逐条测，不起真实网络。
 */
const resHandler = () => http.interceptors.response.handlers[0].fulfilled
const errHandler = () => http.interceptors.response.handlers[0].rejected
const reqHandler = () => http.interceptors.request.handlers[0].fulfilled

const ok = (data, message = 'ok') => ({ data: { code: 200, message, data } })

describe('admin request · 响应归一', () => {
  beforeEach(() => {
    localStorage.clear()
    ElMessage.error.mockClear()
    location.hash = '#/'
  })

  it('code 200 归一为 0，载荷缺省时补 null', () => {
    expect(resHandler()(ok({ id: 1 }, '已保存'))).toEqual({ code: 0, message: '已保存', data: { id: 1 } })
    expect(resHandler()({ data: { code: 200 } })).toEqual({ code: 0, message: 'ok', data: null })
    expect(ElMessage.error).not.toHaveBeenCalled()
  })

  it('非标准响应（文件流 / 纯文本 / 空）原样透传', () => {
    const raw = { data: null }
    expect(resHandler()(raw)).toBeNull()
    expect(resHandler()({ data: 'hello' })).toBe('hello')
    const blob = { list: [1] }
    expect(resHandler()({ data: blob })).toBe(blob)
  })

  it('业务错误透传 code 并弹出后端文案，silent 请求不弹', () => {
    const res = resHandler()({ data: { code: 4220, message: '发售结束时间必须晚于开售时间' }, config: {} })
    expect(res).toEqual({ code: 4220, message: '发售结束时间必须晚于开售时间', data: null })
    expect(ElMessage.error).toHaveBeenCalledWith('发售结束时间必须晚于开售时间')

    ElMessage.error.mockClear()
    const silent = resHandler()({ data: { code: 4003, message: '权限不足' }, config: { _silent: true } })
    expect(silent.code).toBe(4003)
    expect(ElMessage.error).not.toHaveBeenCalled()
  })

  it('缺 message 时给出兜底文案', () => {
    const res = resHandler()({ data: { code: 5001 }, config: {} })
    expect(res.message).toBe('操作失败')
  })
})

describe('admin request · 会话失效', () => {
  beforeEach(() => {
    localStorage.clear()
    ElMessage.error.mockClear()
    location.hash = '#/dashboard'
  })

  it('令牌失效清会话并跳登录', () => {
    localStorage.setItem('sinan_admin_token', 'tk')
    localStorage.setItem('sinan_admin_info', '{"name":"admin"}')

    const res = resHandler()({ data: { code: 4001, message: '登录已失效' }, config: { url: '/users' } })
    expect(res.code).toBe(4001)
    expect(localStorage.getItem('sinan_admin_token')).toBeNull()
    expect(localStorage.getItem('sinan_admin_info')).toBeNull()
    expect(location.hash).toBe('#/login')
    expect(ElMessage.error).toHaveBeenCalledWith('登录已失效')
  })

  it('登录 / 刷新接口自身的 4001 不当作会话失效（避免踢出循环）', () => {
    localStorage.setItem('sinan_admin_token', 'tk')
    for (const url of ['/auth/login', '/auth/refresh']) {
      const res = resHandler()({ data: { code: 4002, message: '密码错误' }, config: { url } })
      expect(res.code).toBe(4002)
    }
    expect(localStorage.getItem('sinan_admin_token')).toBe('tk')
    expect(location.hash).toBe('#/dashboard')
  })

  it('4003 权限不足只报错，不登出', () => {
    localStorage.setItem('sinan_admin_token', 'tk')
    const res = resHandler()({ data: { code: 4003, message: '无该菜单权限' }, config: { url: '/roles' } })
    expect(res.code).toBe(4003)
    expect(localStorage.getItem('sinan_admin_token')).toBe('tk')
    expect(location.hash).toBe('#/dashboard')
  })

  it('已在登录页时不再改写 hash', () => {
    localStorage.setItem('sinan_admin_token', 'tk')
    location.hash = '#/login?redirect=/users'
    resHandler()({ data: { code: 4001 }, config: { url: '/users' } })
    expect(location.hash).toBe('#/login?redirect=/users')
  })
})

describe('admin request · 网络层异常', () => {
  beforeEach(() => {
    ElMessage.error.mockClear()
  })

  const rejected = (err) => errHandler()(err)

  it('网络异常 / 超时给可行动文案，并 resolve 成 code -1 让调用方继续走', async () => {
    await expect(rejected({ code: 'ECONNABORTED' })).resolves.toEqual({
      code: -1, message: '请求超时，请稍后重试', data: null
    })
    await expect(rejected({ message: 'Network Error' })).resolves.toMatchObject({
      code: -1, message: '网络异常，请检查后端服务'
    })
    expect(ElMessage.error).toHaveBeenCalledTimes(2)
  })

  it('HTTP 状态码错误优先用后端返回的 message', async () => {
    const res = await rejected({ response: { data: { message: '令牌已过期' } } })
    expect(res).toEqual({ code: -1, message: '令牌已过期', data: null })
    expect(ElMessage.error).toHaveBeenCalledWith('令牌已过期')
  })
})

describe('admin request · 请求归一', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('本地令牌注入 Authorization，无令牌时不写空头', () => {
    localStorage.setItem('sinan_admin_token', 'tk')
    expect(reqHandler()({ headers: {}, url: '/users' }).headers.Authorization).toBe('Bearer tk')

    localStorage.clear()
    expect(reqHandler()({ headers: {}, url: '/users' }).headers.Authorization).toBeUndefined()
  })

  it('GET 分页参数 size → pageSize，剔除 all / undefined / null 占位', () => {
    const config = reqHandler()({
      headers: {},
      url: '/collectibles',
      params: { page: 1, size: 20, status: 'all', keyword: undefined, categoryId: null, name: 'a' }
    })
    expect(config.params).toEqual({ page: 1, pageSize: 20, name: 'a' })
  })

  it('无 params 的请求不受影响', () => {
    const config = reqHandler()({ headers: {}, url: '/collectibles/1' })
    expect(config.params).toBeUndefined()
  })

  it('get/post 便捷方法把 silent 转成 _silent，GET 走 params、写操作走 data', async () => {
    const spy = vi.spyOn(http, 'request').mockResolvedValue({ code: 0, data: null })
    const { get, post } = await import('@/utils/request')

    get('/collectibles', { page: 1 }, { silent: true })
    expect(spy).toHaveBeenCalledWith({ url: '/collectibles', method: 'get', params: { page: 1 }, _silent: true })

    post('/collectibles', { name: 'x' })
    expect(spy).toHaveBeenCalledWith({ url: '/collectibles', method: 'post', data: { name: 'x' }, _silent: false })

    spy.mockRestore()
  })
})

describe('admin request · 旧页面兼容层', () => {
  it('剥离 /admin 前缀并解包 data 载荷', async () => {
    const spy = vi.spyOn(http, 'get').mockResolvedValue({ code: 0, data: { list: [1, 2], total: 2 } })
    await expect(request.get('/admin/collectibles', { params: { page: 1 } })).resolves.toEqual({
      list: [1, 2], total: 2
    })
    expect(spy).toHaveBeenCalledWith('/collectibles', { params: { page: 1 } })
    spy.mockRestore()
  })

  it('非 0 码抛错，旧页面的 catch 分支仍能拿到文案', async () => {
    const spy = vi.spyOn(http, 'post').mockResolvedValue({ code: 4220, message: '库存池不足' })
    await expect(request.post('/admin/collectibles', {})).rejects.toThrow('库存池不足')
    expect(spy).toHaveBeenCalledWith('/collectibles', {})
    spy.mockRestore()
  })
})
