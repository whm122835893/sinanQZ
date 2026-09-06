/**
 * Axios 封装（管理后台专用）
 *
 * 约定（与后端 AdminResponse 一致）：
 * - 业务响应：{ code, message, data }；code === 200 为成功
 * - 认证失败：4001/4002/4003 → 清除会话并跳转登录页
 * - 无权限：4003/4220 → ElMessage 提示，不中断列表页渲染
 */
import axios, { type AxiosRequestConfig, type AxiosResponse } from 'axios'
import { ElMessage } from 'element-plus'
import { useAuthStore } from '@/stores/auth'
import router from '@/router'

export interface ApiResult<T = any> {
  code: number
  message: string
  data: T
}

const http = axios.create({
  baseURL: '/admin',
  timeout: 20000,
  headers: { 'Content-Type': 'application/json' },
})

// 请求拦截：注入 JWT
http.interceptors.request.use((config) => {
  const auth = useAuthStore()
  if (auth.token) {
    config.headers.Authorization = `Bearer ${auth.token}`
  }
  return config
})

// 响应拦截：统一错误处理
http.interceptors.response.use(
  (response: AxiosResponse<ApiResult>) => {
    const body = response.data
    // 非标准 JSON（如 CSV 导出）直接透传
    if (body === null || typeof body === 'string' || typeof body.code !== 'number') {
      return response
    }
    if (body.code === 200) {
      return response
    }
    // 认证类错误：强制登出
    if ([4001, 4002].includes(body.code)) {
      const auth = useAuthStore()
      auth.logout(false)
      ElMessage.error(body.message || '登录已失效，请重新登录')
      router.push({ path: '/login', query: { redirect: router.currentRoute.value.fullPath } })
      return Promise.reject(body)
    }
    // 业务错误：全局提示（调用方可静默处理）
    ElMessage.error(body.message || '操作失败')
    return Promise.reject(body)
  },
  (error) => {
    if (error.response?.status === 401) {
      const auth = useAuthStore()
      auth.logout(false)
      router.push('/login')
    } else {
      ElMessage.error(error.response?.data?.message || '网络异常，请稍后重试')
    }
    return Promise.reject(error)
  },
)

/** GET：返回 res.data.data */
export async function get<T = any>(url: string, params?: Record<string, any>): Promise<T> {
  const res = await http.get<ApiResult<T>>(url, { params })
  return res.data.data
}

/** POST：返回 res.data.data */
export async function post<T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<T> {
  const res = await http.post<ApiResult<T>>(url, data, config)
  return res.data.data
}

/** PUT：返回 res.data.data */
export async function put<T = any>(url: string, data?: any): Promise<T> {
  const res = await http.put<ApiResult<T>>(url, data)
  return res.data.data
}

/** DELETE：返回 res.data.data */
export async function del<T = any>(url: string): Promise<T> {
  const res = await http.delete<ApiResult<T>>(url)
  return res.data.data
}

/** GET 文本（CSV 导出等） */
export async function getRaw(url: string, params?: Record<string, any>): Promise<string> {
  const res = await http.get(url, { params, responseType: 'text', transformResponse: [(d: unknown) => d] } as any)
  return res.data
}

/** 文件下载（CSV）：走 query token 兼容后端校验 */
export function downloadCsv(filename: string, content: string): void {
  const blob = new Blob(['\uFEFF' + content], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

export default http
