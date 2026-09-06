import axios from 'axios'
import { ElMessage } from 'element-plus'

// ============================================================
// 请求层（真实联调版）
// - http: axios 实例，baseURL /api/admin（vite 代理 → ThinkPHP admin 应用）
// - 响应归一：后端 code === 200 → 前端 { code: 0 }，视图层判定不变
// - 401 家族：清会话跳登录；业务错误统一弹出（silent 请求除外）
// - 分页参数归一：size → pageSize；过滤 'all' 占位值
// ============================================================

const TOKEN_KEY = 'sinan_admin_token'

export const http = axios.create({
  baseURL: '/api/admin',
  timeout: 20000
})

// 仅令牌缺失/无效/过期才视为会话失效（4003 为业务权限不足，不登出）
const AUTH_FAIL_CODES = [4001, 4002]

// ---------- 请求拦截 ----------
http.interceptors.request.use((config) => {
  const token = localStorage.getItem(TOKEN_KEY)
  if (token) config.headers.Authorization = `Bearer ${token}`

  // GET 参数归一：size → pageSize；剔除 'all' / 空串占位
  if (config.params) {
    const p = { ...config.params }
    if (p.size !== undefined) {
      p.pageSize = p.size
      delete p.size
    }
    Object.keys(p).forEach((k) => {
      if (p[k] === 'all' || p[k] === undefined || p[k] === null) delete p[k]
    })
    config.params = p
  }
  return config
})

// ---------- 响应拦截 ----------
http.interceptors.response.use(
  (raw) => {
    const res = raw.data
    // 非标准结构（如文件流）直接透传
    if (res === null || typeof res !== 'object' || !('code' in res)) {
      return res
    }

    // 成功：归一为前端约定 code=0
    if (res.code === 200) {
      return { code: 0, message: res.message || 'ok', data: res.data ?? null }
    }

    // 会话失效：清理并跳登录（登录/刷新接口除外，避免循环）
    const url = raw.config?.url || ''
    const isAuthApi = url.includes('/auth/login') || url.includes('/auth/refresh')
    if (AUTH_FAIL_CODES.includes(res.code) && !isAuthApi && localStorage.getItem(TOKEN_KEY)) {
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem('sinan_admin_info')
      if (!raw.config?._silent) ElMessage.error(res.message || '登录已失效，请重新登录')
      if (!location.hash.startsWith('#/login')) {
        location.hash = '#/login'
      }
      return { code: res.code, message: res.message || '登录已失效', data: null }
    }

    // 业务错误：全局提示（silent 请求自行处理）
    if (!raw.config?._silent) {
      ElMessage.error(res.message || '操作失败')
    }
    return { code: res.code, message: res.message || '操作失败', data: res.data ?? null }
  },
  (error) => {
    const msg = error?.response?.data?.message
      || (error.code === 'ECONNABORTED' ? '请求超时，请稍后重试' : '网络异常，请检查后端服务')
    ElMessage.error(msg)
    return Promise.resolve({ code: -1, message: msg, data: null })
  }
)

// ---------- 便捷方法 ----------
// get/post/put/del(url, dataOrParams, { silent })
const wrap = (method) => (url, data = {}, opts = {}) =>
  http.request({ url, method, ...(method === 'get' ? { params: data } : { data }), _silent: !!opts.silent })

export const get = wrap('get')
export const post = wrap('post')
export const put = wrap('put')
export const del = wrap('delete')

/** 静默 GET（不弹全局错误，页面自行处理） */
export const getSilent = (url, params) => wrap('get')(url, params, { silent: true })
