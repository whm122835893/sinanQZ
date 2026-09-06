import axios from 'axios'

// ============================================================
// 请求层：当前为纯 Mock（不联调），结构上对齐后端约定：
//   - http: axios 实例，联调时直接启用（/api/admin 前缀）
//   - mock(): 本地模拟响应 { code, message, data }
//   - queryList(): 通用分页/关键词/等值过滤
// ============================================================

export const http = axios.create({
  baseURL: '/api/admin',
  timeout: 15000
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem('sinan_admin_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// 模拟响应
export function mock(handler, delay = 240) {
  return new Promise((resolve) => {
    setTimeout(() => {
      try {
        resolve({ code: 0, message: 'ok', data: handler() })
      } catch (e) {
        resolve({ code: 1, message: e.message || '操作失败', data: null })
      }
    }, delay)
  })
}

// 模拟写操作（多弹一次确认级延迟）
export function mockWrite(handler, delay = 400) {
  return mock(handler, delay)
}

/**
 * 通用列表查询
 * @param list     数据源数组
 * @param params   { page, size, keyword, fields: [搜索字段], 其余作为等值过滤（'all'/'' 跳过） }
 */
export function queryList(list, { page = 1, size = 10, keyword = '', fields = [], ...eq } = {}) {
  let data = [...list]
  if (keyword && fields.length) {
    const kw = String(keyword).trim().toLowerCase()
    data = data.filter((i) => fields.some((f) => String(i[f] ?? '').toLowerCase().includes(kw)))
  }
  for (const key of Object.keys(eq)) {
    const v = eq[key]
    if (v === undefined || v === null || v === '' || v === 'all') continue
    data = data.filter((i) => String(i[key]) === String(v))
  }
  const total = data.length
  const start = (page - 1) * size
  return { list: data.slice(start, start + size), total, page, size }
}

// 生成新 id
export const nextId = (list) => (list.length ? Math.max(...list.map((i) => i.id)) + 1 : 1)
