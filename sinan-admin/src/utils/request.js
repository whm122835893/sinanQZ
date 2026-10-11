import axios from 'axios'
import { ElMessage } from 'element-plus'

// ============================================================
// 请求层（真实联调版）
// - http: axios 实例，baseURL /api/admin（vite 代理 → ThinkPHP admin 应用）
// - 响应归一：后端 code === 200 → 前端 { code: 0 }，视图层判定不变
// - 401 家族：清会话跳登录；业务错误统一弹出（silent 请求除外）
// - 网络层异常：按 HTTP 状态码 / 超时 / 连不上 分别给出带成因的文案（describeHttpError）
// - 分页参数归一：size → pageSize；过滤 'all' 占位值
// ============================================================

const TOKEN_KEY = 'sinan_admin_token'

export const http = axios.create({
  baseURL: '/api/admin',
  timeout: 20000
})

// 仅令牌缺失/无效/过期才视为会话失效（4003 为业务权限不足，不登出）
const AUTH_FAIL_CODES = [4001, 4002]

// ---------- 网络层成因归一（2026-10-11 第二批 B6）----------
// 以前这一层只有一句「网络异常，请检查后端服务」，把体积闸门拒收、PHP 没起来、
// 网关超时、接口不存在全揉成同一个提示，运营看不懂、我们也定位不了。
// 现在按「有没有拿到 HTTP 状态码」分两支：
//   有状态码 + 有平台 JSON  → 用后端原话（说明请求真的走到了 PHP）
//   有状态码 + 不是平台 JSON → 按状态码给成因（多半被 nginx / 网关截了）
//   完全没有状态码          → 超时 / 取消 / 连不上，三种分开说
// 文案里带上状态码数字，运营截图过来就能直接判是哪一种。
export function describeHttpError(error) {
  const data = error?.response?.data
  if (data && typeof data === 'object' && data.message) return String(data.message)

  const status = Number(error?.response?.status) || 0
  if (status) {
    // nginx 一类中间层直接回的 HTML/空体，到不了 PHP
    if (status === 413) return '文件太大被服务器拒收（HTTP 413）：请换小一点的图，或让管理员调高上传体积上限'
    if (status === 502 || status === 504) return `后端没有及时应答（HTTP ${status}）：PHP 服务可能正在重启，稍后再试`
    if (status === 404) return '接口不存在（HTTP 404）：前端与后端版本不一致，请先强制刷新，仍不行需重新发版'
    if (status === 429) return '操作过于频繁（HTTP 429）：请过一会儿再试'
    if (status >= 500) return `服务器内部错误（HTTP ${status}）：请把这句报错发给开发`
    return `请求失败（HTTP ${status}）`
  }

  const code = error?.code
  if (code === 'ECONNABORTED' || code === 'ETIMEDOUT') return '请求超时（等了 20 秒没回音）：可重试；反复超时报给开发'
  if (code === 'ERR_CANCELED') return '请求已取消'
  return '连不上服务器：请检查本机网络，或确认后端服务是否在运行'
}

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
    // silent 请求不弹全局错误：调用方（登录页 / 上传 / 密码校验）都会自己把
    // res.message 显示出来，这里再弹一次就成了双重弹窗 —— 与上面业务错误分支保持一致。
    const msg = describeHttpError(error)
    if (!error?.config?._silent) ElMessage.error(msg)
    return Promise.resolve({ code: -1, message: msg, data: null })
  }
)

// ---------- 便捷方法 ----------
// get/post/put/del(url, dataOrParams, { silent, timeout })
// timeout 只给个别请求放宽用：图片上传走的是 1Mbps 出口，全局 20s 大约只够推 2.5MB，
// 而生产在用的藏品图就有 2.4MB —— 再挑一张 4MB 的就会「不知道原因地失败」（RUNBOOK 7.20 S2）。
// 不传就沿用实例的 20s，普通接口不许跟着变长。
const wrap = (method) => (url, data = {}, opts = {}) =>
  http.request({
    url,
    method,
    ...(method === 'get' ? { params: data } : { data }),
    _silent: !!opts.silent,
    ...(opts.timeout ? { timeout: opts.timeout } : {}),
  })

export const get = wrap('get')
export const post = wrap('post')
export const put = wrap('put')
export const del = wrap('delete')

/** 静默 GET（不弹全局错误，页面自行处理） */
export const getSilent = (url, params) => wrap('get')(url, params, { silent: true })

// ---------- 兼容层：axios 风格默认导出 ----------
// 供旧页面使用（request.get('/admin/xxx', { params }) / request.post / request.delete）：
// 1. 剥离 /admin 前缀（baseURL 已是 /api/admin）
// 2. 解包响应：成功返回 data 载荷（旧页面直接读 res.list / res.count），失败抛错
const stripAdmin = (url) => String(url).replace(/^\/admin(?=\/|$)/, '')
async function unwrap(promise) {
  const res = await promise
  if (res && res.code === 0) return res.data
  throw new Error((res && res.message) || '请求失败')
}
const legacyRequest = {
  get: (url, config = {}) => unwrap(http.get(stripAdmin(url), { params: config.params || {} })),
  post: (url, data = {}) => unwrap(http.post(stripAdmin(url), data)),
  put: (url, data = {}) => unwrap(http.put(stripAdmin(url), data)),
  delete: (url) => unwrap(http.delete(stripAdmin(url)))
}
export default legacyRequest
