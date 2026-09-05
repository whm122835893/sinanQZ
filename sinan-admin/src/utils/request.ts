// ============================================================================
// Axios 封装：JWT 注入 / {code:0} 拦截 / 401 跳登录 / 403 提示
// BaseURL 走环境变量 VITE_ADMIN_API_BASE，文档 11.3
// ============================================================================
import axios, { type AxiosInstance, type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios'
import { ElMessage } from 'element-plus'
import type { ApiResponse } from '@/types/api'

const TOKEN_KEY = 'sinan_admin_token'
const REFRESH_KEY = 'sinan_admin_refresh_token'

export function getToken(): string {
  return localStorage.getItem(TOKEN_KEY) ?? ''
}
export function getRefreshToken(): string {
  return localStorage.getItem(REFRESH_KEY) ?? ''
}
export function setTokens(token: string, refreshToken: string): void {
  localStorage.setItem(TOKEN_KEY, token)
  localStorage.setItem(REFRESH_KEY, refreshToken)
}
export function clearTokens(): void {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(REFRESH_KEY)
}

const service: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_ADMIN_API_BASE || '/admin/api/v1',
  timeout: 30000
})

// 请求注入 JWT
service.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// 响应拦截：code===0 放行；401 清理跳登录；其余弹提示；blob 流式下载直接放行
service.interceptors.response.use(
  (response) => {
    if (response.config.responseType === 'blob') {
      return response
    }
    const res = response.data as ApiResponse
    if (res.code === 0) {
      return response
    }
    if (res.code === 401) {
      clearTokens()
      // 避免循环跳转：已在登录页则不重复
      if (!window.location.hash.includes('/login')) {
        ElMessage.error('登录已失效，请重新登录')
        window.location.href = `${window.location.origin}${window.location.pathname}#/login`
      }
      return Promise.reject(new Error(res.message || '未登录'))
    }
    ElMessage.error(res.message || '操作失败')
    return Promise.reject(new Error(res.message || '操作失败'))
  },
  (error) => {
    const msg = error?.response?.data?.message || error.message || '网络异常'
    ElMessage.error(msg)
    return Promise.reject(error)
  }
)

/** GET */
export async function get<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const res = await service.get<ApiResponse<T>>(url, { params, ...baseJson() })
  return res.data.data
}

/** POST */
export async function post<T>(url: string, data?: Record<string, unknown>): Promise<T> {
  const res = await service.post<ApiResponse<T>>(url, data, baseJson())
  return res.data.data
}

/** PUT */
export async function put<T>(url: string, data?: Record<string, unknown>): Promise<T> {
  const res = await service.put<ApiResponse<T>>(url, data, baseJson())
  return res.data.data
}

/** DELETE */
export async function del<T>(url: string, data?: Record<string, unknown>): Promise<T> {
  const res = await service.delete<ApiResponse<T>>(url, { data, ...baseJson() })
  return res.data.data
}

/** 流式文件下载（文档 11.3：导出全部后端流式下载，禁止前端拼接） */
export async function downloadFile(url: string, params?: Record<string, unknown>, filename?: string): Promise<void> {
  const res = await service.get<Blob>(url, {
    params,
    responseType: 'blob',
    headers: { Authorization: `Bearer ${getToken()}` }
  })
  const disposition = (res.headers?.['content-disposition'] as string | undefined) ?? ''
  const match = /filename="?([^";]+)"?/.exec(disposition)
  const blob = new Blob([res.data], { type: res.data.type || 'text/csv;charset=utf-8' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = filename ?? match?.[1] ?? 'export.csv'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(link.href)
}

function baseJson(): AxiosRequestConfig {
  return { headers: { 'Content-Type': 'application/json' } }
}

export default service
